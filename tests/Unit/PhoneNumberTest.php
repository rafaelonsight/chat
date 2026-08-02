<?php

use App\Support\PhoneNumber;

it('normaliza numeros brasileiros para E.164', function (?string $entrada, ?string $esperado) {
    expect(PhoneNumber::toE164($entrada))->toBe($esperado);
})->with([
    ['5511999998888',                 '+5511999998888'],
    ['11999998888',                   '+5511999998888'],
    ['(11) 99999-8888',               '+5511999998888'],
    ['+55 11 99999-8888',             '+5511999998888'],
    ['5511999998888@s.whatsapp.net',  '+5511999998888'],
    ['551133334444',                  '+551133334444'],
    ['1133334444',                    '+551133334444'],
    ['123',                           null],
    ['',                              null],
    [null,                            null],
    ['abc',                           null],
]);
