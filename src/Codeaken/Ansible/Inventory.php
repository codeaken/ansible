<?php
namespace Codeaken\Ansible;

use Codeaken\Ansible\Inventory\Host;

class Inventory
{
    private $hosts = [];

    public function addHost(Host $host)
    {
        $this->hosts[] = $host;
    }

    public function getHostByName($name)
    {
        foreach ($this->hosts as $host) {
            if ($host->getName() == $name) {
                return $host;
            }
        }

        return false;
    }

    public function save($filename)
    {
        foreach ($this->hosts as $host) {
            $name       = $host->getName();
            $ip         = 'ansible_ssh_host=' . $host->getIp();
            $remoteUser = 'ansible_ssh_user=' . $host->getAuth()->getRemoteUser();

            file_put_contents($filename, "$name $ip $remoteUser\n",  FILE_APPEND);
        }
    }
}
