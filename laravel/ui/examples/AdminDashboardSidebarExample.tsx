import React, { lazy, Suspense, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import SenderPoolPanel from '../components/SenderPoolPanel';
import VerifiedMembersPanel from '../components/VerifiedMembersPanel';
import { GATEWAY_BASE, GATEWAY_PAGES } from '../openwa/pages';

// Lazy-loaded OpenWA embedded micro-frontend
const OpenWaApp = lazy(() => import('../openwa/OpenWaApp'));

/**
 * Example demonstrating how to configure the 9-item WhatsApp Gateway sidebar menu
 * inside a Laravel + React Back-Office Admin Dashboard.
 */
export default function AdminDashboardSidebarExample() {
    const location = useLocation();
    const navigate = useNavigate();

    // Detect if current URL is under the embedded gateway (/dashboard/gateway/*)
    const gatewaySubPath = location.pathname.startsWith(GATEWAY_BASE)
        ? location.pathname.slice(GATEWAY_BASE.length).replace(/^\/+|\/+$/g, '')
        : null;

    const [activeTab, setActiveTab] = useState<string>('whatsapp');

    /**
     * THE 9 WHATSAPP GATEWAY SIDEBAR MENU ITEMS:
     * 1. Sesi & Nomor Pengirim WA (Native Panel: SenderPoolPanel)
     * 2. Member Terverifikasi (Native Panel: VerifiedMembersPanel)
     * 3. Dasbor (Embedded Micro-Frontend -> '')
     * 4. Obrolan (Embedded Micro-Frontend -> 'chats')
     * 5. Webhook (Embedded Micro-Frontend -> 'webhooks')
     * 6. Templat (Embedded Micro-Frontend -> 'templates')
     * 7. Penguji Pesan (Embedded Micro-Frontend -> 'message-tester')
     * 8. Plugin (Embedded Micro-Frontend -> 'plugins')
     * 9. Log (Embedded Micro-Frontend -> 'logs')
     */
    const whatsAppGatewayMenuItems = [
        { key: 'whatsapp', label: 'Sesi & Nomor Pengirim WA', type: 'native' },
        { key: 'verified_members', label: 'Member Terverifikasi', type: 'native' },
        ...GATEWAY_PAGES.map((page) => ({
            key: `gw:${page.path}`,
            label: page.label,
            type: 'embedded',
            path: page.path,
        })),
    ];

    const activeKey = gatewaySubPath !== null ? `gw:${gatewaySubPath}` : activeTab;

    const handleSelectMenu = (key: string) => {
        if (key.startsWith('gw:')) {
            const sub = key.slice(3);
            navigate(`${GATEWAY_BASE}${sub ? `/${sub}` : ''}`);
            return;
        }
        if (gatewaySubPath !== null) {
            navigate('/dashboard');
        }
        setActiveTab(key);
    };

    return (
        <div className="admin-layout flex h-screen bg-slate-900 text-slate-100">
            {/* Sidebar Navigation */}
            <aside className="w-64 border-r border-slate-800 bg-slate-950 p-4 flex flex-col">
                <div className="font-bold text-lg text-emerald-400 mb-6">Laravel Back-Office</div>

                <nav className="flex-1 space-y-1">
                    <div className="text-xs font-semibold uppercase tracking-wider text-slate-400 px-3 py-2">
                        WhatsApp Gateway
                    </div>
                    {whatsAppGatewayMenuItems.map((item) => {
                        const isSelected = activeKey === item.key;
                        return (
                            <button
                                key={item.key}
                                onClick={() => handleSelectMenu(item.key)}
                                className={`w-full text-left px-3 py-2 rounded-lg text-sm transition font-medium ${
                                    isSelected
                                        ? 'bg-emerald-600/20 text-emerald-400 border-l-4 border-emerald-500'
                                        : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                }`}
                            >
                                {item.label}
                            </button>
                        );
                    })}
                </nav>
            </aside>

            {/* Main Content Area */}
            <main className="flex-1 overflow-auto p-6">
                {/* 1. Sesi & Nomor Pengirim WA (Native Panel) */}
                {activeKey === 'whatsapp' && (
                    <div className="space-y-4">
                        <h1 className="text-2xl font-bold">Sesi & Nomor Pengirim WA</h1>
                        <p className="text-slate-400">Kelola nomor pengirim, pairing QR code, dan auto-failover rotasi pesan.</p>
                        <SenderPoolPanel />
                    </div>
                )}

                {/* 2. Member Terverifikasi (Native Panel) */}
                {activeKey === 'verified_members' && (
                    <div className="space-y-4">
                        <h1 className="text-2xl font-bold">Member Terverifikasi</h1>
                        <p className="text-slate-400">Daftar pelanggan yang terverifikasi via nomor WhatsApp.</p>
                        <VerifiedMembersPanel />
                    </div>
                )}

                {/* 3 - 9. Embedded Micro-Frontend Pages */}
                {activeKey.startsWith('gw:') && (
                    <div className="h-full">
                        <Suspense fallback={<div className="p-8 text-center text-slate-400">Memuat konsol gateway...</div>}>
                            <OpenWaApp />
                        </Suspense>
                    </div>
                )}
            </main>
        </div>
    );
}
