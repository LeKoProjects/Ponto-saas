<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Feriados nacionais + estadual da Bahia (2 de Julho).
 * Conferir anualmente; feriados municipais de Salvador devem ser cadastrados por empresa.
 */
class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            ['2026-01-01', 'Confraternização Universal', 'nacional'],
            ['2026-04-03', 'Sexta-feira Santa', 'nacional'],
            ['2026-04-21', 'Tiradentes', 'nacional'],
            ['2026-05-01', 'Dia do Trabalho', 'nacional'],
            ['2026-07-02', 'Independência da Bahia', 'estadual'],
            ['2026-09-07', 'Independência do Brasil', 'nacional'],
            ['2026-10-12', 'Nossa Senhora Aparecida', 'nacional'],
            ['2026-11-02', 'Finados', 'nacional'],
            ['2026-11-15', 'Proclamação da República', 'nacional'],
            ['2026-11-20', 'Dia Nacional de Zumbi e da Consciência Negra', 'nacional'],
            ['2026-12-25', 'Natal', 'nacional'],
            ['2027-01-01', 'Confraternização Universal', 'nacional'],
            ['2027-03-26', 'Sexta-feira Santa', 'nacional'],
            ['2027-04-21', 'Tiradentes', 'nacional'],
            ['2027-05-01', 'Dia do Trabalho', 'nacional'],
            ['2027-07-02', 'Independência da Bahia', 'estadual'],
            ['2027-09-07', 'Independência do Brasil', 'nacional'],
            ['2027-10-12', 'Nossa Senhora Aparecida', 'nacional'],
            ['2027-11-02', 'Finados', 'nacional'],
            ['2027-11-15', 'Proclamação da República', 'nacional'],
            ['2027-11-20', 'Dia Nacional de Zumbi e da Consciência Negra', 'nacional'],
            ['2027-12-25', 'Natal', 'nacional'],
        ];

        foreach ($holidays as [$date, $name, $scope]) {
            Holiday::firstOrCreate(['company_id' => null, 'date' => $date], ['name' => $name, 'scope' => $scope]);
        }
    }
}
