<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class LaporanController extends Controller
{
    public function generatePDF()
    {
        $data = [
            'title' => 'Laporan Data Pegawai Minimarket',
            'tanggal' => date('d-m-Y'),
            'pegawai' => [
                ['nama' => 'Andi', 'jabatan' => 'Administrator'],
                ['nama' => 'Budi', 'jabatan' => 'Kasir'],
            ]
        ];


        $pdf = Pdf::loadView('laporan.pegawai', $data);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->download('laporan-pegawai.pdf');
    }
}