<?php
namespace Codeaken\Ansible;

use Codeaken\Ansible\Exception\PlaybookException;
use Codeaken\SshAgent\SshAgent;
use Codeaken\Emitter\EmitterTrait;
use Codeaken\Emitter\EmitterInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class Ansible implements EmitterInterface
{
    use EmitterTrait;

    private $sshAgent;
    private $inventory;
    private $extraVars = [];
    private $hosts = [];

    private $sshArgs = [
        'StrictHostKeyChecking' => 'no',
        'ControlMaster'         => 'auto',
        'ControlPersist'        => '60s',
        'ControlPath'           => '/tmp/ansible-ssh-%h-%p-%r',
    ];

    public function __construct($inventory)
    {
        $this->sshAgent = new SshAgent();
        $this->inventory = $inventory;
    }

    public function setExtraVars(array $extraVars)
    {
        $this->extraVars = $extraVars;
    }

    public function setHosts(array $hosts)
    {
        // @todo Validate that the hosts are in the inventory
        $this->hosts = $hosts;
    }

    public function reset()
    {
        $this->extraVars = [];
        $this->hosts     = [];
    }

    public function runCommand($command)
    {
        $paths = $this->createHome();

        $results = [];
        foreach ($this->hosts as $host) {
            $inventoryHost = $this->inventory->getHostByName($host);

            // Build the command we are going to run
            $builder = new ProcessBuilder([
                'ansible',
                $host,
                '--inventory-file', $paths['inventory'],
                '--module-name', 'command',
                '--args', $command
            ]);

            $this->setEnv($builder, $paths);
            $this->setAuth($builder, $inventoryHost);

            // Create the process and run it
            $ansible = $builder->getProcess();
            $ansible->run();

            // Prepare the results of the run
            $result = [
                'ansible' => [
                    'stdout'   => $ansible->getOutput(),
                    'stderr'   => $ansible->getErrorOutput(),
                    'exitcode' => $ansible->getExitCode(),
                ],
                'remote' => [
                    'output'   => '',
                    'exitcode' => '',
                ]
            ];

            // Ansible exit codes
            // 0 = everything went OK, will also return this if no hosts matched
            // 1 = ansible specific errors
            // 2 = the command on the host has failed
            // 3 = could not connect to the host
            if ($ansible->getExitCode() == 0 || $ansible->getExitCode() == 2) {
                $matches = [];
                preg_match(
                    '/([A-Za-z0-9\.-_:]+) \| (success|FAILED) \| rc=(\d+).*?\n\s*(.*)/s',
                    $ansible->getOutput(),
                    $matches
                );

                $result['remote']['exitcode'] = (int)$matches[3];
                $result['remote']['output']   = $matches[4];
            }

            $results[$host] = $result;
        }

        $this->deleteHome($paths['home']);

        return $results;
    }

    public function runPlaybook($playbook)
    {
        $paths = $this->createHome();

        $results = [];
        foreach ($this->hosts as $host) {
            $inventoryHost = $this->inventory->getHostByName($host);

            // Build the command we are going to run
            $builder = new ProcessBuilder([
                'ansible-playbook',
                $playbook,
                '-v',
                '--inventory-file',  $paths['inventory'],
                '--limit', $host
            ]);

            $this->setEnv($builder, $paths);
            $this->setAuth($builder, $inventoryHost);

            if (count($this->extraVars)) {
                $builder->add('--extra-vars');
                $builder->add(json_encode($this->extraVars));
            }

            // Create the process and run it
            $ansible = $builder->getProcess();
            $ansible->setTimeout(900);  // 15m

            $that = $this;
            $output = '';
            $seenRoles = [];

            $ansible->run(function ($type, $buffer) use ($that, &$output, &$seenRoles) {
                if (Process::OUT == $type) {
                    $output .= $buffer;

                    // Find all complete task blocks
                    preg_match_all(
                        '/(?<action>TASK|NOTIFIED)(?<block>.*?)(?<=\})\n{2}/s',
                        $output,
                        $blockMatches,
                        PREG_SET_ORDER
                    );

                    foreach ($blockMatches as $blockMatch) {
                        $taskBlock = $blockMatch['action'] . $blockMatch['block'];

                        // Get the task info
                        preg_match(
                            '/^(?<action>TASK|NOTIFIED):\s+\[(?:(?<role>.*)\s+\|\s+)?(?<task>.*)\].*$/m',
                            $taskBlock,
                            $taskMatch
                        );

                        $action = $taskMatch['action'];
                        $role   = $taskMatch['role'];
                        $task   = $taskMatch['task'];

                        // Emit if this is a new role
                        if (!in_array($role, $seenRoles)) {
                            $seenRoles[] = $role;
                            $this->emit('role', $role);
                        }

                        // Emit the task we found
                        $this->emit(strtolower($action), $role, $task);

                        // Get the items executed
                        preg_match_all(
                            '/(?<status>ok|changed|failed):\s+\[(?<host>\w*)\](?:\s+=>\s+\(item=(?<item>.*?)\))?\s+=>\s+\{(?<details>.*?)\}/s',
                            $taskBlock,
                            $itemMatches,
                            PREG_SET_ORDER
                        );

                        foreach ($itemMatches as $itemMatch) {
                            $status  = $itemMatch['status'];
                            $host    = $itemMatch['host'];
                            $item    = $itemMatch['item'];
                            $details = json_decode('{' . $itemMatch['details'] . '}', true);

                            // Emit this item
                            $this->emit('item', strtolower($action), $role, $task, $status, $host, $item, $details);
                        }

                        // Remove this block from the input
                        $output = str_replace($taskBlock, '', $output);
                    }
                }
            });

            if ( ! $ansible->isSuccessful()) {
                throw new PlaybookException($ansible);
            }

            $results[$host] = $ansible->getOutput();
        }

        $this->deleteHome($paths['home']);

        return $results;
    }

    private function setEnv(&$builder, $paths)
    {
        // Path to the temporary configuration file we saved earlier
        $builder->setEnv('ANSIBLE_CONFIG', $paths['ansiblecfg']);

        // Temporary home since normally the user we are executing under
        // does not have a home directory set
        $builder->setEnv('HOME', $paths['home']);
    }

    private function setAuth(&$builder, $host)
    {
        $authMethod = $host->getAuth();

        switch ($host->getAuth()->getMethodName()) {
            case 'key':
                if ( ! $this->sshAgent->isRunning()) {
                    $this->sshAgent->start();
                }

                $this->sshAgent->addKey($authMethod->getKey());

                $builder->setEnv(
                    'SSH_AUTH_SOCK', $this->sshAgent->getSocket()
                );
                break;

            case 'password':
                // This only seems to work when running the code in a web site.
                // When running this script in a console the password is not
                // automatically entered but a password prompt is shown instead.
                $builder->add('--ask-pass');
                $builder->setInput($authMethod->getPassword());
                break;

            default:
                // todo: handle unknown authentication method
                break;
        }
    }

    private function createHome()
    {
        // Create the home directory
        $tmpFile = tempnam(sys_get_temp_dir(), 'codeaken_ansible_');

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $tmpDir = $tmpFile;   // Just so to make the following code clearer

        mkdir($tmpDir, 0700);

        // Save the ansible configuration
        $sshArgs = [];
        foreach ($this->sshArgs as $arg => $value) {
            $sshArgs[] = "-o {$arg}={$value}";
        }

        $ansibleCfg = [
            '[ssh_connection]',
            'ssh_args=' . implode(' ', $sshArgs),
            'pipelining=True',
        ];

        file_put_contents("{$tmpDir}/ansible.cfg", implode("\n", $ansibleCfg));

        // Save the inventory
        $this->inventory->save("{$tmpDir}/inventory.ini");

        return [
            'home'       => $tmpDir,
            'inventory'  => "{$tmpDir}/inventory.ini",
            'ansiblecfg' => "{$tmpDir}/ansible.cfg",
        ];
    }

    private function deleteHome($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $currPath = "{$dir}/{$file}";

            if (is_dir($currPath)) {
                $this->deleteHome($currPath);
            } else {
                unlink($currPath);
            }
        }

        return rmdir($dir);
    }
}
