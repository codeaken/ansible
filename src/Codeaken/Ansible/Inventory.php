<?php
namespace Codeaken\Ansible;

class Inventory
{
    private $hosts = [];
    private $hostGroups = [];

    static public function single($name, $ip, AuthMethod $authMethod,$group='default')
    {
        $inventory = new Inventory();
        $inventory->addHost($name, $ip, $authMethod,$group);

        return $inventory;
    }

    /**
     * @param string $group
     * @param string $child
     * @param string $type host|group
     * @throws \Exception
     */
    public function addGroupChildren($group,$child,$type='host')
    {
        if(!in_array($type,['host','group'])){
            throw new \Exception("Invalid group type, a group child could be either host or another group");
        }

        if($type=='host'){
            if(!$this->getHostByName($child)){
               throw new \Exception("Host should be added before group");
            }
        }

        if(!array_key_exists($group, $this->hostGroups)){
            $this->hostGroups[$group]=['host'=>[],'group'=>[]];
        }

        $this->hostGroups[$group][$type][]=['name'=>$child,'type'=>$type];

    }

    public function addHost($name, $ip, AuthMethod $authMethod, $group='default')
    {
        $this->hosts[$name] = new Inventory\Host($name, $ip, $authMethod);
        $this->addGroupChildren($group,$name);
    }

    public function getHostByName($name)
    {
        if ( ! isset($this->hosts[$name])) {
            return false;
        }

        return $this->hosts[$name];
    }

    public function save($filename)
    {
        $lines=[];
        foreach($this->hostGroups as $groupName=>$groups) {

            if($groupName!='default' && count($groups['host'])) $lines[]="[$groupName]";

            foreach ($groups['host'] as $item) {
                $host = $this->getHostByName($item['name']);
                $name = $host->getName();
                $ip = 'ansible_ssh_host=' . $host->getIp();
                $remoteUser = 'ansible_ssh_user=' . $host->getAuth()->getRemoteUser();

                $lines[] = "$name $ip $remoteUser";
            }

            if(isset($groups['group']) && count($groups['group']) )  $lines[]= "[$groupName:children]";

            foreach ($groups['group'] as $item) {
                $lines[]=$item['name'];
            }

        }

        file_put_contents($filename, implode("\n", $lines));
    }
}
