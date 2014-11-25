<?php
namespace Codeaken\Ansible\AuthMethod;

use Codeaken\Ansible\AuthMethod;

class SshAgent extends AuthMethod
{
    private $socket;

    public function __construct($remoteUser, $socket)
    {
        parent::__construct($remoteUser);

        $this->socket = $socket;
    }

    public function getSocket()
    {
        return $this->socket;
    }

    public function getMethodName()
    {
        return 'sshagent';
    }
}
