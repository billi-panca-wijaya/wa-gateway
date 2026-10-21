import SenderPoolPanel from './SenderPoolPanel';

/**
 * Multi-Sender Pool Management Panel for WhatsApp Gateway.
 * Fully supports multiple sender sessions, QR/pairing codes per sender,
 * round-robin rotation, and auto-failover.
 */
export default function WhatsAppGatewayPanel() {
    return <SenderPoolPanel />;
}
