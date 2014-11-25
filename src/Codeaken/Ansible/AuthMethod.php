<?php
namespace Codeaken\Ansible;

abstract class AuthMethod
{
    protected $remoteUser;

    public function __construct($remoteUser)
    {
        $this->remoteUser = $remoteUser;
    }

    public function getRemoteUser()
    {
        return $this->remoteUser;
    }

    abstract public function getMethodName();
}
