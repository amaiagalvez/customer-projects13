<?php

namespace CustomerProjects13\Tests;

use Customers13\Models\Customer;

abstract class DuskTestCase extends \Basics13\Support\Testing\DuskTestCase
{
    protected function customer(): Customer
    {
        return Customer::factory()->create();
    }
}
