<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Project to customer
    |--------------------------------------------------------------------------
    |
    | The column that hangs a project from a customer. It is nullable at the database level because
    | `projects13` owns the table and knows nothing about customers, and this package adds the
    | column to a table that already has rows. The invariant the application relies on — every
    | project has a customer — is enforced by the integration's validation, which is where the
    | field is required, exactly the way the `active` flag is an application rule rather than a
    | database one.
    |
    */

    'project' => [
        'customer_column' => 'customer_id',
        'customer_table' => 'CUM_customers',
    ],

];
