<?php

namespace EntityProjects13\Tests\Unit\Translations;

use Basics13\Tests\TranslationFilesTestCase;

class TranslationFilesTest extends TranslationFilesTestCase
{
    protected function langPath(): string
    {
        return dirname(__DIR__, 3).'/resources/lang';
    }
}
