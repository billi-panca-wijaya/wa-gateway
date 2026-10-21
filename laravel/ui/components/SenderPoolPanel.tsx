import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import Axios from 'axios';
import axios from '../lib/axios';
import { useOverlay } from '../hooks/useReveal';

export interface SenderPoolItem {
    id: number;
    tenant_key: string;
    session_id: string;
    label: string | null;
    status: 'active' | 'suspended' | 'disconnected';
    phone_e164: string | null;
    push_name: string | null;
    last_used_at: string | null;
    suspended_at: string | null;
    suspend_reason: string | null;
    fail_count: number;
    has_api_key: boolean;
    created_at: string;
    updated_at: string;
}

const STATUS_CONFIG: Record<string, { label: string; className: string }> = {
    active: { label: 'Siap Kirim', className: 'badge-status-active' },
    suspended: { label: 'Dijeda Sementara', className: 'badge-status-pending' },
    disconnected: { label: 'Belum Terhubung', className: 'badge-status-inactive' },
};

const REASON_LABELS: Record<string, string> = {
    manual_admin_toggle: 'Dijeda manual oleh admin',
    DELIVERY_FAILED: 'Gagal mengirim pesan terakhir',
    GATEWAY_UNREACHABLE: 'Gateway tidak merespons',
    GATEWAY_HTTP_400: 'Sesi WhatsApp belum tertaut',
    GATEWAY_HTTP_404: 'Sesi belum dibuat di gateway',
};

const formatReason = (reason: string | null) =>
    reason ? (REASON_LABELS[reason] ?? reason) : null;

const formatTime = (iso: string | null) => {
    if (!iso) return 'Belum pernah dipakai';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const errText = (err: unknown) =>
    Axios.isAxiosError(err) ? ((err.response?.data as { message?: string })?.message ?? 'Permintaan gagal.') : 'Permintaan gagal.';

export default function SenderPoolPanel() {
    const [senders, setSenders] = useState<SenderPoolItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busyId, setBusyId] = useState<number | string | null>(null);
    const [filter, setFilter] = useState('');

    // Add Sender Modal
    const [showAddModal, setShowAddModal] = useState(false);
    const [newPhone, setNewPhone] = useState('');
    const [newSessionId, setNewSessionId] = useState('');
    const [newLabel, setNewLabel] = useState('');
    const [newApiKey, setNewApiKey] = useState('');
    const [showAdvancedKey, setShowAdvancedKey] = useState(false);
    const [autoCreateOpenWa, setAutoCreateOpenWa] = useState(true);

    // QR / Pairing Modal
    const [activePairingSender, setActivePairingSender] = useState<SenderPoolItem | null>(null);
    const [qrCode, setQrCode] = useState<string | null>(null);
    const [pairPhone, setPairPhone] = useState('');
    const [pairingCode, setPairingCode] = useState<string | null>(null);
    const [pairingPolling, setPairingPolling] = useState(false);

    // Test Send Modal
    const [activeTestSender, setActiveTestSender] = useState<SenderPoolItem | null>(null);
    const [testPhone, setTestPhone] = useState('');
    const [testMessage, setTestMessage] = useState('');
    const [testResult, setTestResult] = useState<string | null>(null);

    const closeModals = useCallback(() => {
        setShowAddModal(false);
        setActivePairingSender(null);
        setPairingPolling(false);
        setActiveTestSender(null);
    }, []);
    useOverlay(showAddModal || !!activePairingSender || !!activeTestSender, closeModals);

    const loadSenders = useCallback(async () => {
        setLoading(true);
        setError('');
        try {
            const res = await axios.get('/api/v1/admin/sender-pool');
            setSenders(res.data.data || []);
        } catch (err) {
            setError(errText(err));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadSenders();
    }, [loadSenders]);

    const handleAddSender = async (e: FormEvent) => {
        e.preventDefault();
        setBusyId('add');
        setError('');
        setNotice('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            const res = await axios.post('/api/v1/admin/sender-pool', {
                phone: newPhone.trim(),
                session_id: newSessionId.trim() || null,
                label: newLabel.trim() || null,
                api_key: newApiKey.trim() || null,
                auto_create_session: autoCreateOpenWa,
            });
            const created: SenderPoolItem | undefined = res.data?.data;
            setShowAddModal(false);
            setNewPhone('');
            setNewSessionId('');
            setNewLabel('');
            setNewApiKey('');
            setShowAdvancedKey(false);
            setNotice('Nomor pengirim baru berhasil ditambahkan.');
            await loadSenders();
            if (created && autoCreateOpenWa) {
                openPairingModal(created);
            }
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const handleToggleStatus = async (sender: SenderPoolItem) => {
        setBusyId(sender.id);
        setError('');
        setNotice('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            await axios.post(`/api/v1/admin/sender-pool/${sender.id}/toggle`);
            setNotice(
                sender.status === 'active'
                    ? `Nomor "${sender.label || sender.session_id}" dijeda sementara dari antrean OTP.`
                    : `Nomor "${sender.label || sender.session_id}" diaktifkan kembali untuk mengirim OTP.`
            );
            await loadSenders();
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const handleSync = async (sender: SenderPoolItem) => {
        setBusyId(sender.id);
        setError('');
        setNotice('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            await axios.post(`/api/v1/admin/sender-pool/${sender.id}/sync`);
            setNotice(`Status "${sender.label || sender.session_id}" berhasil diperbarui dari gateway.`);
            await loadSenders();
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const handleDeleteSender = async (sender: SenderPoolItem) => {
        if (!window.confirm(`Hapus nomor pengirim "${sender.label || sender.session_id}" dari daftar?`)) {
            return;
        }
        setBusyId(sender.id);
        setError('');
        setNotice('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            await axios.delete(`/api/v1/admin/sender-pool/${sender.id}`);
            setNotice(`Nomor "${sender.label || sender.session_id}" berhasil dihapus.`);
            await loadSenders();
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const openPairingModal = (sender: SenderPoolItem) => {
        setActivePairingSender(sender);
        setQrCode(null);
        setPairingCode(null);
        setPairPhone(sender.phone_e164 ? sender.phone_e164.replace('+', '') : '');
        setPairingPolling(true);
    };

    const loadQrCode = useCallback(async (id: number) => {
        try {
            const res = await axios.get(`/api/v1/admin/sender-pool/${id}/qr`);
            const data = res.data?.data;
            if (data?.qr_code) {
                setQrCode(data.qr_code);
            }
            const st = String(data?.status || '').toLowerCase();
            if (st === 'ready' || st === 'working' || st === 'authenticated') {
                try {
                    const syncRes = await axios.post(`/api/v1/admin/sender-pool/${id}/sync`);
                    const fresh = syncRes.data?.data;
                    setActivePairingSender(null);
                    setPairingPolling(false);
                    setNotice(`WhatsApp "${fresh?.label || fresh?.session_id}" (${fresh?.phone_e164 || 'nomor pengirim'}) berhasil terhubung!`);
                    void loadSenders();
                    return;
                } catch {
                    setActivePairingSender(null);
                    setPairingPolling(false);
                    setNotice('WhatsApp berhasil terhubung!');
                    void loadSenders();
                    return;
                }
            }
        } catch {
            // QR not ready yet
        }

        // Check status directly via sync (works for both QR scan and 8-character code)
        try {
            const syncRes = await axios.post(`/api/v1/admin/sender-pool/${id}/sync`);
            const fresh = syncRes.data?.data;
            const liveSt = String(syncRes.data?.live?.status || fresh?.status || '').toLowerCase();
            if (liveSt === 'ready' || liveSt === 'working' || liveSt === 'authenticated' || (fresh?.status === 'active' && fresh?.phone_e164)) {
                setActivePairingSender(null);
                setPairingPolling(false);
                setNotice(`WhatsApp "${fresh?.label || fresh?.session_id}" (${fresh?.phone_e164 || 'nomor pengirim'}) berhasil terhubung!`);
                void loadSenders();
            }
        } catch {
            // non-blocking
        }
    }, [loadSenders]);

    const handleRequestPairing = async (e: FormEvent) => {
        e.preventDefault();
        if (!activePairingSender) return;
        setBusyId('pair-code');
        setError('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            const res = await axios.post(`/api/v1/admin/sender-pool/${activePairingSender.id}/pairing-code`, {
                phone: pairPhone,
            });
            setPairingCode(res.data.data.pairing_code);
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    useEffect(() => {
        if (!activePairingSender || !pairingPolling) return;
        void loadQrCode(activePairingSender.id);

        const timer = setInterval(() => {
            void loadQrCode(activePairingSender.id);
        }, 2500);

        return () => clearInterval(timer);
    }, [activePairingSender, pairingPolling, loadQrCode]);

    const handleSessionAction = async (action: 'start' | 'stop' | 'logout') => {
        if (!activePairingSender) return;
        if (action === 'logout' && !window.confirm('Lepas tautan WhatsApp dari nomor pengirim ini?')) return;
        setBusyId(`session-${action}`);
        setError('');
        setNotice('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            await axios.post(`/api/v1/admin/sender-pool/${activePairingSender.id}/${action}`);
            const labelMap = { start: 'dimulai', stop: 'dihentikan', logout: 'dilepas tautannya' };
            setNotice(`Sesi "${activePairingSender.label || activePairingSender.session_id}" berhasil ${labelMap[action]}.`);
            await loadSenders();
            if (action === 'start') {
                void loadQrCode(activePairingSender.id);
            }
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const handleTestSend = async (e: FormEvent) => {
        e.preventDefault();
        if (!activeTestSender) return;
        setBusyId('test-send');
        setTestResult(null);
        setError('');
        try {
            await axios.get('/sanctum/csrf-cookie');
            const res = await axios.post(`/api/v1/admin/sender-pool/${activeTestSender.id}/test-send`, {
                phone: testPhone,
                message: testMessage || undefined,
            });
            setTestResult(res.data.message || 'Pesan uji berhasil dikirim.');
        } catch (err) {
            setError(errText(err));
        } finally {
            setBusyId(null);
        }
    };

    const activeCount = senders.filter((s) => s.status === 'active').length;
    const attentionCount = senders.filter((s) => s.status !== 'active' || s.fail_count > 0).length;

    const filteredSenders = senders.filter((s) => {
        const q = filter.trim().toLowerCase();
        if (!q) return true;
        return (
            s.session_id.toLowerCase().includes(q) ||
            (s.label ?? '').toLowerCase().includes(q) ||
            (s.phone_e164 ?? '').toLowerCase().includes(q) ||
            (s.push_name ?? '').toLowerCase().includes(q)
        );
    });

    return (
        <div className="stack">
            {/* Summary Metrics */}
            <div className="stats-grid">
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-green" />
                    <div className="stat-details">
                        <h3>Nomor Siap Kirim</h3>
                        <p className="stat-number text-success">{activeCount}</p>
                    </div>
                </div>
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-indigo" />
                    <div className="stat-details">
                        <h3>Total Nomor Terdaftar</h3>
                        <p className="stat-number">{senders.length}</p>
                    </div>
                </div>
                <div className="stat-card glass-panel">
                    <span className="stat-accent accent-amber" />
                    <div className="stat-details">
                        <h3>Dijeda / Perlu Perhatian</h3>
                        <p className="stat-number">{attentionCount}</p>
                    </div>
                </div>
            </div>

            <div className="glass-panel gw-panel">
                {/* Header & Toolbar */}
                <div className="gw-header">
                    <div className="gw-title-wrap">
                        <div className="gw-title-row">
                            <h3>Daftar Nomor Pengirim OTP</h3>
                            <span className="badge badge-status-active">
                                {activeCount} dari {senders.length} Aktif
                            </span>
                        </div>
                        <p className="gw-subtitle">
                            Tambahkan satu atau beberapa nomor WhatsApp pengirim. Sistem membagi pengiriman OTP secara bergantian dan otomatis beralih ke nomor lain bila ada yang terputus.
                        </p>
                    </div>

                    <div className="gw-toolbar">
                        {senders.length > 0 && (
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
                                    placeholder="Cari nama, kode sesi, atau nomor..."
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                    aria-label="Cari nomor pengirim"
                                />
                                {filter && (
                                    <button
                                        type="button"
                                        className="fx-clear"
                                        onClick={() => setFilter('')}
                                        aria-label="Bersihkan pencarian"
                                    >
                                        &times;
                                    </button>
                                )}
                            </div>
                        )}
                        <button
                            type="button"
                            className="btn btn-sm btn-outline"
                            onClick={() => void loadSenders()}
                            disabled={loading || busyId !== null}
                        >
                            {loading ? 'Memuat...' : 'Segarkan'}
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-primary"
                            onClick={() => setShowAddModal(true)}
                        >
                            + Tambah Nomor Pengirim
                        </button>
                    </div>
                </div>

                {error && <div className="alert error" role="alert">{error}</div>}
                {notice && <div className="alert success">{notice}</div>}

                {/* Table */}
                <div className="admin-table-container">
                    <table className="admin-table">
                        <thead>
                            <tr>
                                <th>Nama &amp; Kode Sesi</th>
                                <th>Nomor WhatsApp</th>
                                <th>Status</th>
                                <th>Terakhir Mengirim</th>
                                <th>Riwayat Gagal</th>
                                <th style={{ textAlign: 'right' }}>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading && senders.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="empty-state">
                                        <span className="spinner large" />
                                        <p className="mt-2">Memuat daftar nomor pengirim...</p>
                                    </td>
                                </tr>
                            ) : filteredSenders.length === 0 ? (
                                <tr>
                                    <td colSpan={6}>
                                        <div className="gw-empty">
                                            <p className="gw-empty-title">
                                                {senders.length === 0
                                                    ? 'Belum ada nomor tambahan di daftar pengirim'
                                                    : 'Tidak ada nomor pengirim yang cocok dengan pencarian'}
                                            </p>
                                            <p className="gw-empty-desc">
                                                {senders.length === 0
                                                    ? 'Klik tombol "+ Tambah Nomor Pengirim" di kanan atas untuk mendaftarkan nomor WhatsApp pengirim OTP baru.'
                                                    : 'Coba kata kunci lain atau bersihkan kotak pencarian.'}
                                            </p>
                                            {senders.length === 0 && (
                                                <span className="gw-empty-note">
                                                    Selama daftar ini kosong, pengiriman OTP tetap berjalan memakai sesi utama (otp-sender).
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredSenders.map((sender) => {
                                    const st = STATUS_CONFIG[sender.status] || { label: sender.status, className: 'badge-role-user' };
                                    const isBusy = busyId === sender.id;
                                    const reason = formatReason(sender.suspend_reason);

                                    return (
                                        <tr key={sender.id}>
                                            <td>
                                                <div className="gw-cell-primary">
                                                    {sender.label || sender.session_id}
                                                </div>
                                                <div className="gw-cell-sub">
                                                    Sesi: <code title={sender.session_id}>{sender.session_id.length > 14 ? `${sender.session_id.slice(0, 8)}…` : sender.session_id}</code>
                                                    {sender.has_api_key && (
                                                        <span className="gw-tag" style={{ marginLeft: '0.45rem' }}>
                                                            Kunci Khusus
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td>
                                                {sender.phone_e164 ? (
                                                    <div>
                                                        <span className="gw-phone-link">{sender.phone_e164}</span>
                                                        {sender.push_name && (
                                                            <div className="gw-cell-sub">{sender.push_name}</div>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-muted">Belum ditautkan (klik Tautkan WA)</span>
                                                )}
                                            </td>
                                            <td>
                                                <span className={`badge ${st.className}`}>
                                                    <span className="gw-status-dot" />
                                                    {st.label}
                                                </span>
                                                {reason && (
                                                    <div className="gw-reason" title={reason}>
                                                        {reason}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="text-muted">
                                                {formatTime(sender.last_used_at)}
                                            </td>
                                            <td>
                                                {sender.fail_count > 0 ? (
                                                    <span className="badge badge-status-inactive">
                                                        {sender.fail_count}x gagal
                                                    </span>
                                                ) : (
                                                    <span className="text-success">Normal (0)</span>
                                                )}
                                            </td>
                                            <td style={{ textAlign: 'right' }}>
                                                <div className="gw-action-row">
                                                    <button
                                                        type="button"
                                                        className="btn gw-btn-xs btn-primary"
                                                        onClick={() => openPairingModal(sender)}
                                                        disabled={isBusy}
                                                    >
                                                        Tautkan WA
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn gw-btn-xs btn-outline"
                                                        onClick={() => {
                                                            setActiveTestSender(sender);
                                                            setTestPhone('');
                                                            setTestResult(null);
                                                        }}
                                                        disabled={isBusy}
                                                    >
                                                        Tes
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn gw-btn-xs btn-outline"
                                                        onClick={() => void handleSync(sender)}
                                                        disabled={isBusy}
                                                        title="Perbarui status dari gateway"
                                                    >
                                                        Sync
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn gw-btn-xs btn-outline"
                                                        onClick={() => void handleToggleStatus(sender)}
                                                        disabled={isBusy}
                                                    >
                                                        {sender.status === 'active' ? 'Jeda' : 'Aktifkan'}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn gw-btn-xs btn-action-delete"
                                                        onClick={() => void handleDeleteSender(sender)}
                                                        disabled={isBusy}
                                                    >
                                                        Hapus
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Modal: Add Sender */}
            {showAddModal && (
                <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setShowAddModal(false)}>
                    <div className="modal-content glass-panel admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="add-sender-title">
                        <button
                            type="button"
                            className="modal-close"
                            aria-label="Tutup"
                            onClick={() => setShowAddModal(false)}
                        >
                            &times;
                        </button>
                        <div className="gw-modal-header">
                            <h3 id="add-sender-title">Tambah Nomor Pengirim Baru</h3>
                            <p>Masukkan nomor WhatsApp agent/staff yang akan dipakai mengirim kode OTP ke pelanggan.</p>
                        </div>

                        {error && <div className="alert error" role="alert">{error}</div>}

                        <form onSubmit={handleAddSender} className="auth-form">
                            <div className="form-group">
                                <label htmlFor="sender-phone">
                                    Nomor WhatsApp Agent / Pengirim <span className="required">*</span>
                                </label>
                                <div className="fx-field">
                                    <span className="fx-field-icon" aria-hidden="true">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                        </svg>
                                    </span>
                                    <input
                                        id="sender-phone"
                                        type="tel"
                                        inputMode="tel"
                                        className="fx-input fx-input-mono"
                                        placeholder="Contoh: 081234567890 atau +6281234567890"
                                        value={newPhone}
                                        onChange={(e) => setNewPhone(e.target.value)}
                                        required
                                        autoFocus
                                    />
                                </div>
                                <small className="hint">Nomor WhatsApp aktif milik agent/CS yang disiapkan sebagai pengirim OTP.</small>
                            </div>

                            <div className="form-group">
                                <label htmlFor="sender-label">Nama Pengenal / Label Agent</label>
                                <div className="fx-field">
                                    <input
                                        id="sender-label"
                                        type="text"
                                        className="fx-input"
                                        placeholder="Contoh: WA CS 1 - Agent Budi"
                                        value={newLabel}
                                        onChange={(e) => setNewLabel(e.target.value)}
                                    />
                                </div>
                                <small className="hint">Nama yang tampil di tabel agar Anda mudah mengenali pemilik nomor ini.</small>
                            </div>

                            <label className="gw-check-row" htmlFor="autoCreate">
                                <input
                                    type="checkbox"
                                    id="autoCreate"
                                    checked={autoCreateOpenWa}
                                    onChange={(e) => setAutoCreateOpenWa(e.target.checked)}
                                />
                                <span>
                                    Siapkan sesi otomatis &amp; langsung buka layar tautan WhatsApp
                                    <small>Setelah disimpan, Anda langsung bisa scan QR atau minta kode 8 karakter untuk nomor di atas.</small>
                                </span>
                            </label>

                            <div className="mt-2">
                                <button
                                    type="button"
                                    className="gw-details-toggle"
                                    onClick={() => setShowAdvancedKey((v) => !v)}
                                >
                                    {showAdvancedKey ? '▾ Sembunyikan pengaturan teknis' : '▸ Pengaturan teknis (opsional)'}
                                </button>
                                {showAdvancedKey && (
                                    <div className="mt-2">
                                        <div className="form-group">
                                            <label htmlFor="sender-session-id">Kode Sesi Gateway (Opsional)</label>
                                            <div className="fx-field">
                                                <input
                                                    id="sender-session-id"
                                                    type="text"
                                                    className="fx-input fx-input-mono"
                                                    placeholder="Otomatis dari nomor WA bila dikosongkan"
                                                    value={newSessionId}
                                                    onChange={(e) => setNewSessionId(e.target.value)}
                                                    pattern="[a-zA-Z0-9_-]+"
                                                    title="Hanya huruf, angka, tanda hubung (-), atau garis bawah (_)"
                                                />
                                            </div>
                                            <small className="hint">Biarkan kosong agar sistem membuat kode sesi otomatis dari nomor telepon.</small>
                                        </div>

                                        <div className="form-group">
                                            <label htmlFor="sender-api-key">Kunci API Khusus Sesi Ini (Opsional)</label>
                                            <div className="fx-field">
                                                <input
                                                    id="sender-api-key"
                                                    type="password"
                                                    className="fx-input fx-input-mono"
                                                    placeholder="Biarkan kosong untuk memakai kunci bawaan server"
                                                    value={newApiKey}
                                                    onChange={(e) => setNewApiKey(e.target.value)}
                                                />
                                            </div>
                                            <small className="hint">
                                                Kosongkan saja pada penggunaan normal. Jika diisi, kunci disimpan terenkripsi di server.
                                            </small>
                                        </div>
                                    </div>
                                )}
                            </div>

                            <div className="gw-modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-outline"
                                    onClick={() => setShowAddModal(false)}
                                    disabled={busyId === 'add'}
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={busyId === 'add' || !newPhone.trim()}
                                >
                                    {busyId === 'add' ? <span className="spinner" /> : 'Simpan & Tautkan WA'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Pairing / QR Code */}
            {activePairingSender && (
                <div
                    className="modal-overlay"
                    onClick={(e) => {
                        if (e.target === e.currentTarget) {
                            setActivePairingSender(null);
                            setPairingPolling(false);
                        }
                    }}
                >
                    <div className="modal-content glass-panel gw-modal-wide" role="dialog" aria-modal="true" aria-labelledby="pair-sender-title">
                        <button
                            type="button"
                            className="modal-close"
                            aria-label="Tutup"
                            onClick={() => {
                                setActivePairingSender(null);
                                setPairingPolling(false);
                            }}
                        >
                            &times;
                        </button>

                        <div className="gw-modal-header">
                            <h3 id="pair-sender-title">
                                Hubungkan WhatsApp: {activePairingSender.label || activePairingSender.session_id}
                            </h3>
                            <p>
                                Nomor tertaut saat ini:{' '}
                                <strong>{activePairingSender.phone_e164 || 'Belum ada nomor yang tertaut'}</strong>
                            </p>
                        </div>

                        {error && <div className="alert error" role="alert">{error}</div>}
                        {notice && <div className="alert success">{notice}</div>}

                        <div className="gw-session-bar">
                            <span className="text-muted" style={{ fontSize: '0.85rem' }}>
                                Kontrol sesi gateway:
                            </span>
                            <div className="gateway-actions">
                                <button
                                    type="button"
                                    className="btn btn-sm btn-primary"
                                    onClick={() => void handleSessionAction('start')}
                                    disabled={busyId?.toString().startsWith('session-')}
                                >
                                    Mulai / Tampilkan QR
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline"
                                    onClick={() => void handleSessionAction('stop')}
                                    disabled={busyId?.toString().startsWith('session-')}
                                >
                                    Hentikan
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline text-danger"
                                    onClick={() => void handleSessionAction('logout')}
                                    disabled={busyId?.toString().startsWith('session-')}
                                >
                                    Lepas Tautan
                                </button>
                            </div>
                        </div>

                        <div className="gateway-pairing-grid">
                            <div className="gw-pair-box" style={{ textAlign: 'center' }}>
                                <div>
                                    <h4>Cara 1: Scan Kode QR</h4>
                                    <p className="hint">
                                        Buka WhatsApp di HP pengirim &rarr; Perangkat Tertaut &rarr; Tautkan Perangkat.
                                    </p>
                                </div>
                                {qrCode ? (
                                    <img
                                        src={qrCode}
                                        alt="QR Pairing WhatsApp"
                                        className="gateway-qr"
                                    />
                                ) : (
                                    <div className="empty-state" style={{ padding: '2rem 1rem' }}>
                                        <span className="spinner large" />
                                        <p className="hint mt-2">
                                            Menunggu QR dari gateway... Klik &quot;Mulai / Tampilkan QR&quot; bila belum muncul.
                                        </p>
                                    </div>
                                )}
                            </div>

                            <div className="gw-pair-box">
                                <form onSubmit={handleRequestPairing}>
                                    <h4>Cara 2: Pakai Kode 8 Karakter</h4>
                                    <p className="hint">
                                        Masukkan nomor WhatsApp HP pengirim untuk mendapatkan kode tautan tanpa kamera.
                                    </p>
                                    <div className="form-group mt-2">
                                        <label htmlFor="pool-pair-phone">Nomor HP Pengirim</label>
                                        <div className="fx-field">
                                            <input
                                                id="pool-pair-phone"
                                                type="tel"
                                                inputMode="tel"
                                                className="fx-input"
                                                placeholder="Contoh: 081234567890"
                                                value={pairPhone}
                                                onChange={(e) => setPairPhone(e.target.value)}
                                                required
                                            />
                                        </div>
                                    </div>
                                    <button
                                        type="submit"
                                        className="btn btn-sm btn-outline btn-block"
                                        disabled={busyId === 'pair-code'}
                                    >
                                        {busyId === 'pair-code' ? <span className="spinner" /> : 'Minta Kode Tautan'}
                                    </button>
                                </form>

                                {pairingCode && (
                                    <div className="gw-code-box">
                                        <small>Kode Tautan Anda</small>
                                        <span className="gw-code-value">{pairingCode}</span>
                                        <span className="hint">Masukkan kode ini pada notifikasi WhatsApp di HP pengirim.</span>
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="gw-modal-footer">
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => {
                                    setActivePairingSender(null);
                                    setPairingPolling(false);
                                    void loadSenders();
                                }}
                            >
                                Selesai
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Modal: Test Send */}
            {activeTestSender && (
                <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && setActiveTestSender(null)}>
                    <div className="modal-content glass-panel admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="test-send-title">
                        <button
                            type="button"
                            className="modal-close"
                            aria-label="Tutup"
                            onClick={() => setActiveTestSender(null)}
                        >
                            &times;
                        </button>
                        <div className="gw-modal-header">
                            <h3 id="test-send-title">
                                Kirim Pesan Uji: {activeTestSender.label || activeTestSender.session_id}
                            </h3>
                            <p>Pastikan nomor pengirim ini dapat mengirim pesan WhatsApp dengan lancar.</p>
                        </div>

                        <form onSubmit={handleTestSend} className="auth-form">
                            <div className="form-group">
                                <label htmlFor="test-target-phone">
                                    Nomor WhatsApp Tujuan <span className="required">*</span>
                                </label>
                                <div className="fx-field">
                                    <input
                                        id="test-target-phone"
                                        type="tel"
                                        inputMode="tel"
                                        className="fx-input"
                                        placeholder="Contoh: 081234567890"
                                        value={testPhone}
                                        onChange={(e) => setTestPhone(e.target.value)}
                                        required
                                        autoFocus
                                    />
                                </div>
                            </div>

                            <div className="form-group">
                                <label htmlFor="test-target-msg">Isi Pesan Uji (Opsional)</label>
                                <div className="fx-field">
                                    <textarea
                                        id="test-target-msg"
                                        className="fx-textarea"
                                        rows={3}
                                        placeholder="Kosongkan untuk memakai teks uji standar..."
                                        value={testMessage}
                                        onChange={(e) => setTestMessage(e.target.value)}
                                    />
                                </div>
                            </div>

                            {testResult && <div className="alert success">{testResult}</div>}

                            <div className="gw-modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-outline"
                                    onClick={() => setActiveTestSender(null)}
                                    disabled={busyId === 'test-send'}
                                >
                                    Tutup
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={busyId === 'test-send' || !testPhone.trim()}
                                >
                                    {busyId === 'test-send' ? <span className="spinner" /> : 'Kirim Pesan Uji'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
