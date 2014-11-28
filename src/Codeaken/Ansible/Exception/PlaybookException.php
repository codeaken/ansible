<?php
namespace Codeaken\Ansible\Exception;

class PlaybookException extends \Exception
{
    const GENERAL_ERROR = 1;
    const NOT_FOUND     = 2;
    const SYNTAX_ERROR  = 3;

    const GENERAL_ERROR_MSG = 'A general error occured while running the playbook';
    const NOT_FOUND_MSG     = 'The playbook was not found';
    const SYNTAX_ERROR_MSG  = 'There is a syntax error in the playbook';

    private $process;

    public function __construct(\Symfony\Component\Process\Process $process)
    {
        $this->process = $process;

        // Figure out what specific error occured
        $error = $process->getErrorOutput();

        if (false !== strpos($error, 'the playbook') &&
            false !== strpos($error, 'could not be found')
        ) {

            parent::__construct(self::NOT_FOUND_MSG, self::NOT_FOUND);

        } else if (false !== strpos($error, 'Syntax Error while loading YAML script')) {

            parent::__construct(self::SYNTAX_ERROR_MSG, self::SYNTAX_ERROR);

        } else {

            parent::__construct(self::GENERAL_ERROR_MSG, self::GENERAL_ERROR);
        }
    }

    public function getProcess()
    {
        return $this->process;
    }
}
