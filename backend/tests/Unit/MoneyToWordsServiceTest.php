<?php

namespace Tests\Unit;

use App\Domain\Documents\MoneyToWordsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyToWordsServiceTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function amounts(): array
    {
        return [
            'zero' => ['0.00', 'CERO PESOS 00/100 M.N.'],
            'one peso' => ['1.00', 'UN PESO 00/100 M.N.'],
            'fifty cents' => ['0.50', 'CERO PESOS 50/100 M.N.'],
            'one cent' => ['0.01', 'CERO PESOS 01/100 M.N.'],
            'sixteen' => ['16.00', 'DIECISÉIS PESOS 00/100 M.N.'],
            'twenty' => ['20.00', 'VEINTE PESOS 00/100 M.N.'],
            'twenty one' => ['21.00', 'VEINTIÚN PESOS 00/100 M.N.'],
            'twenty two' => ['22.00', 'VEINTIDÓS PESOS 00/100 M.N.'],
            'thirty one' => ['31.00', 'TREINTA Y UN PESOS 00/100 M.N.'],
            'ninety nine' => ['99.00', 'NOVENTA Y NUEVE PESOS 00/100 M.N.'],
            'one hundred' => ['100.00', 'CIEN PESOS 00/100 M.N.'],
            'one hundred one' => ['101.00', 'CIENTO UN PESOS 00/100 M.N.'],
            'five hundred' => ['500.00', 'QUINIENTOS PESOS 00/100 M.N.'],
            'nine hundred twenty' => ['920.00', 'NOVECIENTOS VEINTE PESOS 00/100 M.N.'],
            'office sample cents' => ['920.17', 'NOVECIENTOS VEINTE PESOS 17/100 M.N.'],
            'one thousand' => ['1000.00', 'MIL PESOS 00/100 M.N.'],
            'one thousand one' => ['1001.00', 'MIL UN PESOS 00/100 M.N.'],
            'nine thousand eight hundred' => ['9800.00', 'NUEVE MIL OCHOCIENTOS PESOS 00/100 M.N.'],
            'twenty one thousand' => ['21000.00', 'VEINTIÚN MIL PESOS 00/100 M.N.'],
            'one hundred one thousand' => ['101000.00', 'CIENTO UN MIL PESOS 00/100 M.N.'],
            'one hundred thousand' => ['100000.00', 'CIEN MIL PESOS 00/100 M.N.'],
            'one million' => ['1000000.00', 'UN MILLÓN DE PESOS 00/100 M.N.'],
            'one million one' => ['1000001.00', 'UN MILLÓN UN PESOS 00/100 M.N.'],
            'two million' => ['2000000.00', 'DOS MILLONES DE PESOS 00/100 M.N.'],
            'two and half million' => ['2500000.50', 'DOS MILLONES QUINIENTOS MIL PESOS 50/100 M.N.'],
            'one thousand million' => ['1000000000.00', 'MIL MILLONES DE PESOS 00/100 M.N.'],
            'twenty one million' => ['21000000.00', 'VEINTIÚN MILLONES DE PESOS 00/100 M.N.'],
            'maximum' => [
                '9999999999.99',
                'NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE MILLONES NOVECIENTOS NOVENTA Y NUEVE MIL NOVECIENTOS NOVENTA Y NUEVE PESOS 99/100 M.N.',
            ],
            'single decimal digit' => ['920.5', 'NOVECIENTOS VEINTE PESOS 50/100 M.N.'],
            'no decimals' => ['920', 'NOVECIENTOS VEINTE PESOS 00/100 M.N.'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_converts_amounts_to_fiscal_words(string $amount, string $expected): void
    {
        $this->assertSame($expected, (new MoneyToWordsService)->convert($amount));
    }

    /** @return array<string, array{string}> */
    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-1.00'],
            'above maximum' => ['10000000000.00'],
            'three decimals' => ['1.005'],
            'text' => ['abc'],
            'empty' => [''],
            'thousands separator' => ['1,000.00'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_unsupported_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MoneyToWordsService)->convert($amount);
    }
}
