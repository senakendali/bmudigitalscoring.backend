<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Jadwal Pertandingan Seni</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        h4, h5 { margin: 0; padding: 0; font-size:18px; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }

        .table { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .table th, .table td { padding: 8px; font-size: 11px; border-bottom: 1px solid #ddd; }
        .table thead th { font-weight: bold; }
        .table tbody tr:nth-child(even) { background-color: #f7f7f7; }

        .logo { width: 120px; }

        .soft-dark { background-color:#495057; color:#FFFFFF; height:50px; }
        .dark      { background-color:#343A40; color:#FFFFFF; }

        .blue-corner { background-color:#002FB9; color:#FFFFFF; }
        .red-corner  { background-color:#F80000; color:#FFFFFF; }

        .contingent { font-style: italic; font-size: 10px; opacity: .95; }
        .names { line-height: 1.2rem; }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <table style="width: 100%; margin-bottom: 10px;">
        <tr>
            <td style="width: 25%;">
                <img src="{{ public_path('images/ipsi.png') }}" class="logo">
            </td>
            <td style="width: 50%; text-align: center;">
                <h4 class="uppercase fw-bold">JADWAL {{ $data['arena_name'] }}</h4>
                <h4 class="uppercase fw-bold">{{ $data['tournament_name'] }}</h4>
                <div class="uppercase fw-bold">
                    {{ \Carbon\Carbon::parse($data['scheduled_date'])->translatedFormat('d F Y') }}
                </div>
            </td>
            <td style="width: 25%;"></td>
        </tr>
    </table>

    {{-- ===================== BATTLE: SATU TABEL GABUNGAN ===================== --}}
    @if(!empty($battle_rows))
        <table class="table">
            <thead>
                <tr>
                    <th class="soft-dark">PARTAI</th>
                    <th class="soft-dark">BABAK</th>
                    <th class="soft-dark">KELAS</th>
                    <th class="soft-dark text-center" colspan="2">PESERTA</th>
                    <th class="soft-dark text-center" colspan="2">WAKTU</th>
                    <th class="soft-dark text-center" colspan="2">SCORE</th>
                </tr>
            </thead>
            <tbody>
            @foreach($battle_rows as $row)
                <tr>
                    <td>{{ $row['order'] }}</td>
                    <td class="uppercase">{{ $row['round_label'] ?? '-' }}</td>
                    <td class="uppercase">{{ $row['class_label'] ?? '-' }}</td>

                    {{-- BLUE --}}
<td class="blue-corner" style="width: 25%;">
    @php
        $bn = $row['blue']['names'] ?? null;
        $bc = $row['blue']['contingent'] ?? null;
    @endphp

    @if($bn)
        <div class="names">{{ $bn }}</div>
    @elseif(!empty($row['source_blue_text']))
        <div class="names">{{ $row['source_blue_text'] }}</div>
    @elseif(!empty($row['source_blue_order']))
        <div class="names">Pemenang Partai #{{ $row['source_blue_order'] }}</div>
    @else
        <div class="names">-</div>
    @endif

    @if($bc)
        <div class="contingent">{{ $bc }}</div>
    @endif
</td>

{{-- RED --}}
<td class="red-corner" style="width: 25%;">
    @php
        $rn = $row['red']['names'] ?? null;
        $rc = $row['red']['contingent'] ?? null;
    @endphp

    @if($rn)
        <div class="names">{{ $rn }}</div>
    @elseif(!empty($row['source_red_text']))
        <div class="names">{{ $row['source_red_text'] }}</div>
    @elseif(!empty($row['source_red_order']))
        <div class="names">Pemenang Partai #{{ $row['source_red_order'] }}</div>
    @else
        <div class="names">-</div>
    @endif

    @if($rc)
        <div class="contingent">{{ $rc }}</div>
    @endif
</td>


                    {{-- waktu/score kiri-kanan (simetris) --}}
                    <td class="text-center">{{ $row['time']  ?? '-' }}</td>
                    <td class="text-center">{{ $row['time']  ?? '-' }}</td>
                    <td class="text-center">{{ $row['score'] ?? '-' }}</td>
                    <td class="text-center">{{ $row['score'] ?? '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- ===================== NON-BATTLE: PER POOL ===================== --}}
    @if(!empty($non_battle_tables))
        @foreach($non_battle_tables as $tb)
            <table class="table">
                <thead>
                    <tr>
                        <th class="soft-dark">PARTAI</th>
                        <th class="soft-dark">KONTINGEN</th>
                        <th class="soft-dark" colspan="3">NAMA ATLET</th>
                        <th class="soft-dark text-center">WAKTU</th>
                        <th class="soft-dark text-center">SCORE</th>
                    </tr>
                    <tr>
                        <th colspan="7" class="dark text-center fw-bold uppercase">
                            {{ $tb['title']['category'] }}
                            {{ $tb['title']['gender'] === 'male' ? 'PUTRA' : 'PUTRI' }}
                            {{ strtoupper($tb['title']['age'] ?? '-') }} - {{ $tb['title']['pool'] }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tb['rows'] as $m)
                        <tr>
                            <td>{{ $m['match_order'] ?? '-' }}</td>
                            <td>{{ $m['contingent']['name'] ?? '-' }}</td>

                            @if (($m['match_type'] ?? '') === 'seni_tunggal')
                                <td colspan="3">{{ $m['team_member1']['name'] ?? '-' }}</td>
                            @elseif (($m['match_type'] ?? '') === 'seni_ganda')
                                <td>{{ $m['team_member1']['name'] ?? '-' }}</td>
                                <td>{{ $m['team_member2']['name'] ?? '-' }}</td>
                                <td>-</td>
                            @elseif (($m['match_type'] ?? '') === 'seni_regu')
                                <td>{{ $m['team_member1']['name'] ?? '-' }}</td>
                                <td>{{ $m['team_member2']['name'] ?? '-' }}</td>
                                <td>{{ $m['team_member3']['name'] ?? '-' }}</td>
                            @else
                                <td colspan="3">{{ $m['team_member1']['name'] ?? '-' }}</td>
                            @endif

                            <td class="text-center">{{ $m['match_time'] ?? '-' }}</td>
                            <td class="text-center">{{ $m['final_score'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif

</body>
</html>
