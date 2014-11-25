<?php
namespace Codeaken\Ansible\Inventory;

use Codeaken\Ansible\AuthMethod;

class Host
{
    private $name;
    private $ip;
    private $auth;

    public function __construct($name, $ip, AuthMethod $auth)
    {
        $this->name = $name;
        $this->ip    = $ip;
        $this->auth  = $auth;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function getAuth()
    {
        return $this->auth;
    }
}
