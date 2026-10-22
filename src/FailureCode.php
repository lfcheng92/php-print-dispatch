<?php
declare(strict_types=1);

namespace Lfcheng\PrintDispatch;

enum FailureCode: string
{
    case MissingExecutable = 'missing_executable';
    case SpawnFailed = 'spawn_failed';
    case Timeout = 'command_timeout';
    case OutputLimit = 'output_limit';
    case CommandFailed = 'command_failed';
    case UnrecognizedResponse = 'unrecognized_response';
    case SpoolInvalid = 'spool_invalid';
    case TransportException = 'transport_exception';
    case SafeRejection = 'safe_rejection';
    case PrinterNotAllowed = 'printer_not_allowed';
    case LeaseExpired = 'lease_expired';
    case OperatorConfirmedAbsent = 'operator_confirmed_not_submitted';
}
