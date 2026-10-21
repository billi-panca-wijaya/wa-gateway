import { useCallback, useEffect, useState } from 'react';
import Axios from 'axios';
import axios from '../lib/axios';

interface VerifiedMember {
    id: number;
    uuid: string;
    name: string | null;
    email: string | null;
    tenant_key: string;
    phone_e164: string;
    phone_verified_at: string;
    last_login_at: string | null;
    is_active: boolean;
    created_at: string;
}

interface MetaStats {
    total_verified: number;
    verified_today: number;
    verified_last_7_days: number;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    stats: MetaStats;
}

const formatDateTime = (iso: string | null) => {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const initialOf = (m: VerifiedMember) =>
    (m.name ?? m.phone_e164 ?? '?').replace('+', '').trim().charAt(0).toUpperCase() || '?';

const errText = (err: unknown) =>
    Axios.isAxiosError(err) ? ((err.response?.data as { message?: string })?.message ?? 'Permintaan gagal.') : 'Permintaan gagal.';

export default function VerifiedMembersPanel() {
    const [members, setMembers] = useState<VerifiedMember[]>([]);
    const [meta, setMeta] = useState<PaginationMeta | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [page, setPage] = useState(1);

    useEffect(() => {
        const t = window.setTimeout(() => setDebouncedSearch(search), 220);
        return () => window.clearTimeout(t);
    }, [search]);

    const loadMembers = useCallback(async (targetPage = 1, searchQuery = '') => {
        setLoading(true);
        setError('');
        try {
            const params = new URLSearchParams();
            params.set('page', targetPage.toString());
            params.set('per_page', '20');
            if (searchQuery.trim()) {
                params.set('search', searchQuery.trim());
            }

            const res = await axios.get(`/api/v1/admin/verified-members?${params.toString()}`);
            setMembers(res.data.data || []);
            setMeta(res.data.meta || null);
            setPage(targetPage);
        } catch (err) {
            setError(errText(err));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadMembers(1, debouncedSearch);
    }, [loadMembers, debouncedSearch]);

    const stats = meta?.stats;

    return (
        <div className="stack">
            {/* Summary Metrics */}
            <div className="stats-grid">
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-green" />
                    <div className="stat-details">
                        <h3>Total Member Terverifikasi</h3>
                        <p className="stat-number text-success">{stats?.total_verified ?? 0}</p>
                    </div>
                </div>
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-indigo" />
                    <div className="stat-details">
                        <h3>Verifikasi Hari Ini</h3>
                        <p className="stat-number text-primary">{stats?.verified_today ?? 0}</p>
                    </div>
                </div>
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-amber" />
                    <div className="stat-details">
                        <h3>7 Hari Terakhir</h3>
                        <p className="stat-number">{stats?.verified_last_7_days ?? 0}</p>
                    </div>
                </div>
            </div>

            <div className="glass-panel gw-panel">
                {/* Header & Animated Search Bar */}
                <div className="gw-header">
                    <div className="gw-title-wrap">
                        <div className="gw-title-row">
                            <h3>Daftar Member Terverifikasi WhatsApp</h3>
                            {stats && (
                                <span className="badge badge-status-active">
                                    {stats.total_verified} Member
                                </span>
                            )}
                        </div>
                        <p className="gw-subtitle">
                            Pelanggan yang telah berhasil login dan memverifikasi nomor WhatsApp mereka dengan kode OTP 6 digit.
                        </p>
                    </div>

                    <div className="gw-toolbar">
                        <div className="fx-field fx-search-wrap">
                            <span className="fx-field-icon" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="m21 21-4.3-4.3" />
                                </svg>
                            </span>
                            <input
                                type="search"
                                className="fx-input"
                                placeholder="Cari nomor WhatsApp, nama, atau email..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Escape' && search) {
                                        e.stopPropagation();
                                        setSearch('');
                                    }
                                }}
                                aria-label="Cari member terverifikasi"
                            />
                            {search ? (
                                <button
                                    type="button"
                                    className="fx-clear"
                                    onClick={() => setSearch('')}
                                    aria-label="Bersihkan pencarian"
                                >
                                    &times;
                                </button>
                            ) : (
                                <span className="fx-kbd">CARI</span>
                            )}
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline"
                            onClick={() => void loadMembers(page, debouncedSearch)}
                            disabled={loading}
                        >
                            {loading ? 'Memuat...' : 'Segarkan'}
                        </button>
                    </div>
                </div>

                {error && <div className="alert error" role="alert">{error}</div>}

                {/* Table */}
                <div className="admin-table-container">
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Nomor WhatsApp</th>
                                <th>Tenant</th>
                                <th>Waktu Verifikasi</th>
                                <th>Login Terakhir</th>
                                <th style={{ textAlign: 'right' }}>Status Akun</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && members.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="empty-state">
                                        <span className="spinner large" />
                                        <p className="mt-2">Memuat daftar member terverifikasi...</p>
                                    </td>
                                </tr>
                            ) : members.length === 0 ? (
                                <tr>
                                    <td colSpan={6}>
                                        <div className="gw-empty">
                                            <p className="gw-empty-title">
                                                {debouncedSearch
                                                    ? 'Tidak ada member yang cocok dengan pencarian'
                                                    : 'Belum ada member yang terverifikasi via WhatsApp'}
                                            </p>
                                            <p className="gw-empty-desc">
                                                {debouncedSearch
                                                    ? 'Coba masukkan sebagian nomor HP (mis. 812...) atau nama akun lain.'
                                                    : 'Setiap pelanggan yang berhasil login menggunakan OTP WhatsApp akan otomatis tercatat di halaman ini.'}
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                members.map((member) => (
                                    <tr key={member.id}>
                                        <td>
                                            <div className="user-cell">
                                                <div className="user-cell-avatar" aria-hidden="true">
                                                    {initialOf(member)}
                                                </div>
                                                <div>
                                                    <div className="gw-cell-primary">
                                                        {member.name || 'Belum Mengisi Nama'}
                                                    </div>
                                                    <div className="gw-cell-sub">
                                                        {member.email || `ID #${member.id}`}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <a
                                                href={`https://wa.me/${member.phone_e164.replace(/\D/g, '')}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="gw-phone-link"
                                                title="Buka chat WhatsApp"
                                            >
                                                {member.phone_e164}
                                            </a>
                                        </td>
                                        <td>
                                            <span className="gw-tag gw-tag-muted">
                                                {member.tenant_key}
                                            </span>
                                        </td>
                                        <td className="text-muted">
                                            {formatDateTime(member.phone_verified_at)}
                                        </td>
                                        <td className="text-muted">
                                            {formatDateTime(member.last_login_at)}
                                        </td>
                                        <td style={{ textAlign: 'right' }}>
                                            <span className={`badge ${member.is_active ? 'badge-status-active' : 'badge-status-inactive'}`}>
                                                <span className="gw-status-dot" />
                                                {member.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination Controls */}
                {meta && meta.last_page > 1 && (
                    <div className="gw-pagination">
                        <span>
                            Menampilkan halaman <strong>{meta.current_page}</strong> dari <strong>{meta.last_page}</strong> ({meta.total} member)
                        </span>
                        <div className="gw-action-row">
                            <button
                                type="button"
                                className="btn btn-sm btn-outline"
                                onClick={() => void loadMembers(page - 1, debouncedSearch)}
                                disabled={page <= 1 || loading}
                            >
                                &larr; Sebelumnya
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-outline"
                                onClick={() => void loadMembers(page + 1, debouncedSearch)}
                                disabled={page >= meta.last_page || loading}
                            >
                                Berikutnya &rarr;
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
