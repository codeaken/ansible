<?php
namespace Codeaken\Ansible;

use Symfony\Component\Process\ProcessBuilder;

class Ansible
{
    private $inventory;

    private $sshArgs = [
        'StrictHostKeyChecking' => 'no',
        'ControlMaster'         => 'auto',
        'ControlPersist'        => '60s',
        'ControlPath'           => '/tmp/ansible-ssh-%h-%p-%r',
    ];

    public function __construct($inventory)
    {
        $this->inventory = $inventory;
    }

    public function runCommand($command, $hosts)
    {
        if ( ! is_array($hosts)) {
            $hosts = [$hosts];
        }

        $tmpInventory  = $this->saveInventory();
        $tmpAnsibleCfg = $this->saveAnsibleCfg();

        $results = [];
        foreach ($hosts as $host) {
            $inventoryHost = $this->inventory->getHostByName($host);

            // Build the command we are going to run
            $builder = new ProcessBuilder([
                'ansible',
                $host,
                '--inventory-file', $tmpInventory,
                '--module-name', 'command',
                '--args', $command
            ]);

            $this->setEnv($builder, $tmpAnsibleCfg);
            $this->setAuth($builder, $inventoryHost);

            // Create the process and run it
            $ansible = $builder->getProcess();
            //var_dump($ansible);
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

        unlink($tmpInventory);
        unlink($tmpAnsibleCfg);

        return $results;
    }

    public function runPlaybook($playbook, $host)
    {
        $tmpInventory  = $this->saveInventory();
        $tmpAnsibleCfg = $this->saveAnsibleCfg();

        $inventoryHost = $this->inventory->getHostByName($host);

        // Build the command we are going to run
        $builder = new ProcessBuilder([
            'ansible-playbook',
            $playbook,
            '--inventory-file',  $tmpInventory,
            '--limit', $host
        ]);

        $this->setEnv($builder, $tmpAnsibleCfg);
        $this->setAuth($builder, $inventoryHost);

        // Create the process and run it
        $ansible = $builder->getProcess();
        $ansible->run();

        unlink($tmpInventory);
        unlink($tmpAnsibleCfg);

        return $ansible->getOutput();
    }

    private function setEnv(&$builder, $ansibleCfgPath)
    {
        // Path to the temporary configuration file we saved earlier
        $builder->setEnv('ANSIBLE_CONFIG', $ansibleCfgPath);

        // Temporary home since normally the user we are executing under
        // does not have a home directory set
        $builder->setEnv('HOME', '/tmp');
    }

    private function setAuth(&$builder, $host)
    {
        $authMethod = $host->getAuth();

        switch ($host->getAuth()->getMethodName()) {
            case 'sshagent':
                $builder->setEnv(
                    'SSH_AUTH_SOCK', $authMethod->getSocket()
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

    private function saveInventory()
    {
        $tmpInventoy = tempnam(sys_get_temp_dir(), 'codeaken_ansible_inv_');
        $this->inventory->save($tmpInventoy);

        return $tmpInventoy;
    }

    private function saveAnsibleCfg()
    {
        $tmpAnsibleCfg = tempnam(sys_get_temp_dir(), 'codeaken_ansible_cfg_');

        // Combine the arguments
        $sshArgs = [];
        foreach ($this->sshArgs as $arg => $value) {
            $sshArgs[] = "-o {$arg}={$value}";
        }

        // Build the lines of the config file
        $ansibleCfg = [
            '[ssh_connection]',
            'ssh_args=' . implode(' ', $sshArgs),
            'pipelining=True',
        ];

        file_put_contents($tmpAnsibleCfg, implode("\n", $ansibleCfg));

        return $tmpAnsibleCfg;
    }
}
