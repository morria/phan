<?php

declare(strict_types=1);

namespace Phan\Exception;

use AssertionError;
use Phan\Language\FQSEN;

/**
 * Thrown to indicate that retrieving the element for an FQSEN from the CodeBase failed.
 */
class CodeBaseException extends \Exception
{

    /** @var FQSEN|null the FQSEN that cannot be found in the code base */
    private $missing_fqsen;

    /**
     * @param FQSEN|null $missing_fqsen
     * The FQSEN that cannot be found in the code base
     *
     * @param string $message
     * The error message
     */
    public function __construct(
        ?FQSEN $missing_fqsen = null,
        string $message = ""
    ) {
        parent::__construct($message);
        $this->missing_fqsen = $missing_fqsen;
    }

    /**
     * @return bool
     * True if we have an FQSEN defined
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasFQSEN(): bool
    {
        return !\is_null($this->missing_fqsen);
    }

    /**
     * @return FQSEN
     * The missing FQSEN
     */
    public function getFQSEN(): FQSEN
    {
        $fqsen = $this->missing_fqsen;
        if (!$fqsen) {
            throw new AssertionError('Should check CodeBaseException->hasFQSEN()');
        }
        return $fqsen;
    }
}
