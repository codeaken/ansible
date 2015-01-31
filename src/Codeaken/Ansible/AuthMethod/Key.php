<?php
namespace Codeaken\Ansible\AuthMethod;

use Codeaken\SshKey\SshPrivateKey;

class Key extends \Codeaken\Ansible\AuthMethod
{
    private $key;

    public function __construct($remoteUser, SshPrivateKey $key)
    {
        parent::__construct($remoteUser);

        $this->key = $key;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function getMethodName()
    {
        return 'key';
    }
}
