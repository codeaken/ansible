<?php
namespace Codeaken\Ansible\Exception;

class PlaybookException extends \Exception
{
    const GENERAL_ERROR      = 1;
    const NOT_FOUND          = 2;
    const SYNTAX_ERROR       = 3;
    const UNDEFINED_VARIABLE = 4;

    const GENERAL_ERROR_MSG      = 'A general error occured while running the playbook';
    const NOT_FOUND_MSG          = 'The playbook was not found';
    const SYNTAX_ERROR_MSG       = 'There is a syntax error in the playbook';
    const UNDEFINED_VARIABLE_MSG = 'One or more undefined variables';

    private $process;

    public function __construct(\Symfony\Component\Process\Process $process)
    {
        $this->process = $process;

        $code    = 0;
        $message = '';

        // Not all of the ansible errors have output in stderr. Therefore, if
        // stderr is empty we will use the stdout output instead to get clues
        // on what the actual error was.
        $error = $process->getErrorOutput();

        if (is_null($error)) {
            $error = $process->getOutput();
        }

        // Figure out the specific error that occured
        if (false !== strpos($error, 'the playbook') && false !== strpos($error, 'could not be found')) {
            $code    = self::NOT_FOUND;
            $message = self::NOT_FOUND_MSG;
        } else if (false !== strpos($error, 'Syntax Error while loading YAML script')) {
            $code    = self::SYNTAX_ERROR;
            $message = self::SYNTAX_ERROR_MSG;
        } else if (false !== strpos($error, 'One or more undefined variables')) {
            $code    = self::UNDEFINED_VARIABLE;
            $message = self::UNDEFINED_VARIABLE_MSG;
        } else {
            $code    = self::GENERAL_ERROR;
            $message = self::GENERAL_ERROR_MSG;
        }

        parent::__construct($message, $code);
    }

    public function getProcess()
    {
        return $this->process;
    }
}
