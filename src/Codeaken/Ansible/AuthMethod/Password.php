<?php
namespace Codeaken\Ansible\AuthMethod;

use Codeaken\Ansible\AuthMethod;

class Password extends AuthMethod
{
    private $password;

    public function __construct($remoteUser, $password)
    {
        parent::__construct($remoteUser);

        $this->password = $password;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getMethodName()
    {
        return 'password';
    }
}
