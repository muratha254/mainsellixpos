<div class="table-responsive">
    <table class="table table-bordered sx-gold-table">
        <thead>
            <tr>
                <th>When</th>
                @if(!empty($showBusiness))
                    <th>Business</th>
                @endif
                <th>User</th>
                <th>Branch</th>
                <th>Activity</th>
                <th>Detail</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr>
                    <td>{{ optional($log->created_at)->format('d M Y H:i') ?: '-' }}</td>
                    @if(!empty($showBusiness))
                        <td>
                            @if($log->company)
                                <a href="{{ route('owner.businesses.show', $log->company) }}">{{ $log->company->name }}</a>
                            @else
                                -
                            @endif
                        </td>
                    @endif
                    <td>{{ optional($log->user)->name ?: (data_get($log->after_json, 'username') ?: '-') }}</td>
                    <td>{{ optional($log->branch)->name ?: (data_get($log->after_json, 'branch') ?: '-') }}</td>
                    <td>{{ \App\Services\AuditLogger::humanAction((string) $log->action, $log->module) }}</td>
                    <td>{{ \App\Services\AuditLogger::detailLine($log) ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ !empty($showBusiness) ? 6 : 5 }}">No activity recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
