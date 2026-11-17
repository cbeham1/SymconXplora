<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateXploraCloud(): void
    {
        $this->validateModule(__DIR__ . '/../XploraCloud');
    }

    public function testValidateXploraWatch(): void
    {
        $this->validateModule(__DIR__ . '/../XploraWatch');
    }

    public function testValidateXploraConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../XploraConfigurator');
    }
}
