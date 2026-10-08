<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:100%;margin:26px 0;table-layout:fixed;background:#f7f4ff;border:1px solid #e5def2;border-radius:12px;">
    @if (! empty($title))
        <tr>
            <td colspan="2" style="max-width:100%;padding:18px 20px 10px;color:#6843c2;font-size:11px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;box-sizing:border-box;overflow-wrap:anywhere;word-wrap:break-word;">{{ $title }}</td>
        </tr>
    @endif
    @foreach ($rows as $row)
        <tr>
            <td class="info-label" width="38%" valign="top" style="width:38%;max-width:100%;padding:{{ $loop->first && empty($title) ? '18px' : '10px' }} 10px {{ $loop->last ? '18px' : '10px' }} 20px;color:#727586;font-size:13px;line-height:20px;box-sizing:border-box;overflow-wrap:anywhere;word-wrap:break-word;">{{ $row['label'] }}</td>
            <td class="info-value" width="62%" align="right" valign="top" style="width:62%;max-width:100%;padding:{{ $loop->first && empty($title) ? '18px' : '10px' }} 20px {{ $loop->last ? '18px' : '10px' }} 10px;color:#251e2e;font-size:13px;font-weight:700;line-height:20px;box-sizing:border-box;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;">{{ $row['value'] }}</td>
        </tr>
    @endforeach
</table>
