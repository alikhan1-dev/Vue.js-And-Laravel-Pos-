<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed units of measure (UOM) for products
    |--------------------------------------------------------------------------
    | Optional UOM field; validate against this list when provided.
    | Add or remove codes as needed for your POS (e.g. piece, kg, box, liter).
    */
    'allowed_uom' => [
        'piece',
        'pcs',
        'kg',
        'g',
        'liter',
        'l',
        'ml',
        'box',
        'carton',
        'pack',
        'meter',
        'm',
        'cm',
        'unit',
        'each',
        'dozen',
        'roll',
        'bottle',
        'can',
        'bag',
    ],

];
