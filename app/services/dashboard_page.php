<?php

function dashboardTransactionCashierQueueCount(mysqli $conn): int
{
    if (function_exists('transactionWorkflowSupportReady')) {
        transactionWorkflowSupportReady($conn);
    }

    if (schemaColumnExists($conn, 'transaksi', 'workflow_step')) {
        return (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE workflow_step = 'cashier'");
    }

    return (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE status = 'pending'");
}

function buildDashboardExtraCss(): string
{
    return <<<'HTML'
<style>
.dashboard-page {
    gap: 20px;
}

.dashboard-showcase {
    position: relative;
    padding: clamp(18px, 2vw, 28px);
    border-radius: 34px;
    border: 1px solid rgba(255, 255, 255, 0.72);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.64));
    box-shadow: 0 34px 74px rgba(15, 23, 42, 0.1);
    overflow: hidden;
    isolation: isolate;
}

.dashboard-showcase::before,
.dashboard-showcase::after {
    content: '';
    position: absolute;
    border-radius: 999px;
    filter: blur(2px);
    opacity: 0.92;
    pointer-events: none;
    z-index: 0;
}

.dashboard-showcase::before {
    width: 220px;
    height: 220px;
    top: -118px;
    right: 8%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.12) 68%, transparent 72%);
}

.dashboard-showcase::after {
    width: 180px;
    height: 180px;
    bottom: -94px;
    left: 12%;
    background: radial-gradient(circle, rgba(15, 118, 110, 0.14) 0%, rgba(15, 118, 110, 0.04) 52%, transparent 72%);
}

body.dark .dashboard-showcase {
    border-color: rgba(173, 191, 215, 0.14);
    background: linear-gradient(180deg, rgba(11, 19, 31, 0.92), rgba(11, 19, 31, 0.78));
    box-shadow: 0 34px 74px rgba(2, 8, 23, 0.42);
}

body.dark .dashboard-showcase::before {
    background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, rgba(255, 255, 255, 0.02) 42%, transparent 72%);
}

body.dark .dashboard-showcase::after {
    background: radial-gradient(circle, rgba(15, 118, 110, 0.2) 0%, rgba(15, 118, 110, 0.05) 52%, transparent 72%);
}

.dashboard-showcase-top,
.dashboard-showcase-grid {
    position: relative;
    z-index: 1;
}

.dashboard-showcase-top {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(280px, 0.85fr);
    gap: 20px;
    align-items: start;
    margin-bottom: 18px;
}

.showcase-kicker,
.showcase-panel-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.dashboard-showcase-title {
    margin: 8px 0 0;
    font-size: clamp(1.72rem, 2.5vw, 2.4rem);
    line-height: 1.04;
    letter-spacing: -0.05em;
    color: var(--text);
}

.dashboard-showcase-description {
    max-width: 640px;
    margin: 12px 0 0;
    color: var(--text-muted);
    font-size: 0.92rem;
    line-height: 1.7;
}

.dashboard-showcase-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}

.dashboard-showcase-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    padding: 8px 14px;
    border-radius: 999px;
    border: 1px solid rgba(221, 228, 236, 0.88);
    background: rgba(255, 255, 255, 0.76);
    color: var(--text-2);
    font-size: 0.77rem;
    font-weight: 700;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
    backdrop-filter: blur(12px);
}

body.dark .dashboard-showcase-chip {
    border-color: rgba(173, 191, 215, 0.14);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-2);
    box-shadow: none;
}

.dashboard-showcase-toolbar {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

.dashboard-showcase-search {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 52px;
    padding: 14px 16px;
    border-radius: 20px;
    border: 1px solid rgba(221, 228, 236, 0.84);
    background: rgba(255, 255, 255, 0.82);
    color: var(--text-muted);
    box-shadow: var(--shadow-xs);
    backdrop-filter: blur(16px);
}

body.dark .dashboard-showcase-search {
    border-color: rgba(173, 191, 215, 0.14);
    background: rgba(255, 255, 255, 0.04);
}

.dashboard-showcase-search i {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 118, 110, 0.1);
    color: var(--primary);
    flex-shrink: 0;
}

.dashboard-showcase-search span {
    min-width: 0;
    font-size: 0.8rem;
    line-height: 1.5;
}

.dashboard-showcase-actions {
    width: 100%;
    margin-left: 0;
    justify-content: flex-end;
}

.dashboard-showcase-actions .btn {
    flex: 1 1 160px;
}

.dashboard-showcase-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.78fr) minmax(0, 0.92fr);
    gap: 18px;
}

.showcase-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-height: 100%;
    padding: 18px;
    border-radius: 28px;
    border: 1px solid rgba(221, 228, 236, 0.82);
    background: rgba(255, 255, 255, 0.78);
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(18px);
}

body.dark .showcase-panel {
    border-color: rgba(173, 191, 215, 0.14);
    background: rgba(255, 255, 255, 0.04);
}

.showcase-panel-dark {
    border-color: rgba(255, 255, 255, 0.08);
    background: linear-gradient(160deg, #070d18 0%, #101a2a 56%, #192232 100%);
    color: #eef2ff;
    box-shadow: 0 30px 70px rgba(2, 8, 23, 0.34);
}

body.dark .showcase-panel-dark {
    background: linear-gradient(160deg, #060c16 0%, #0e1828 56%, #151f2f 100%);
}

.showcase-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.showcase-panel-title {
    margin: 4px 0 0;
    font-size: 1.08rem;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.03em;
    color: inherit;
}

.showcase-panel-note {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.8rem;
    line-height: 1.7;
}

.showcase-panel-dark .showcase-panel-kicker,
.showcase-panel-dark .showcase-panel-note,
.showcase-preview-copy p,
.showcase-team-module p,
.showcase-team-module-foot span,
.showcase-preview-pill {
    color: rgba(226, 232, 240, 0.76);
}

.showcase-panel-badge,
.showcase-file-badge,
.showcase-timeline-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.1);
    color: var(--primary);
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.showcase-timeline-card {
    grid-column: 1 / span 2;
}

.showcase-timeline {
    display: grid;
    gap: 12px;
}

.showcase-timeline-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    border-radius: 20px;
    border: 1px solid rgba(221, 228, 236, 0.74);
    background: rgba(248, 250, 252, 0.88);
    color: inherit;
    text-decoration: none;
    transition: transform var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
}

body.dark .showcase-timeline-item {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.03);
}

.showcase-timeline-item:hover,
.showcase-file-item:hover,
.showcase-team-module:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}

.showcase-timeline-icon,
.showcase-file-icon,
.showcase-team-module-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.92rem;
    flex-shrink: 0;
}

.showcase-timeline-icon {
    background: rgba(15, 118, 110, 0.1);
    color: var(--primary);
}

.showcase-timeline-item.tone-warning .showcase-timeline-icon {
    background: rgba(180, 83, 9, 0.14);
    color: var(--warning);
}

.showcase-timeline-item.tone-danger .showcase-timeline-icon {
    background: rgba(180, 35, 24, 0.14);
    color: var(--danger);
}

.showcase-timeline-item.tone-success .showcase-timeline-icon {
    background: rgba(21, 128, 61, 0.14);
    color: var(--success);
}

.showcase-timeline-copy,
.showcase-file-copy {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}

.showcase-timeline-copy strong,
.showcase-file-copy strong {
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -0.02em;
}

.showcase-timeline-copy span,
.showcase-file-copy span {
    font-size: 0.76rem;
    color: var(--text-muted);
    line-height: 1.55;
}

.showcase-progress-card {
    align-items: stretch;
}

.showcase-progress-visual {
    display: flex;
    justify-content: center;
    padding-top: 4px;
}

.showcase-progress-ring {
    --progress: 50;
    width: 168px;
    aspect-ratio: 1;
    border-radius: 50%;
    padding: 14px;
    background:
        radial-gradient(circle at center, rgba(255, 255, 255, 0.95) 0 55%, transparent 56%),
        conic-gradient(var(--primary) 0 calc(var(--progress) * 1%), rgba(15, 23, 42, 0.08) calc(var(--progress) * 1%) 100%);
    display: grid;
    place-items: center;
}

body.dark .showcase-progress-ring {
    background:
        radial-gradient(circle at center, #08121f 0 55%, transparent 56%),
        conic-gradient(var(--primary) 0 calc(var(--progress) * 1%), rgba(255, 255, 255, 0.08) calc(var(--progress) * 1%) 100%);
}

.showcase-progress-ring-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-align: center;
}

.showcase-progress-ring-inner strong {
    font-size: 1.86rem;
    line-height: 1;
    letter-spacing: -0.06em;
    color: var(--text);
}

.showcase-progress-ring-inner span {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.showcase-micro-grid {
    display: grid;
    gap: 10px;
}

.showcase-micro-stat {
    padding: 12px 14px;
    border-radius: 18px;
    border: 1px solid rgba(221, 228, 236, 0.74);
    background: rgba(248, 250, 252, 0.72);
}

body.dark .showcase-micro-stat {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.03);
}

.showcase-micro-stat span {
    display: block;
    color: var(--text-muted);
    font-size: 0.72rem;
}

.showcase-micro-stat strong {
    display: block;
    margin-top: 6px;
    color: var(--text);
    font-size: 0.98rem;
    letter-spacing: -0.03em;
}

.showcase-library-card {
    justify-content: space-between;
}

.showcase-file-list {
    display: grid;
    gap: 12px;
}

.showcase-file-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 20px;
    border: 1px solid rgba(221, 228, 236, 0.74);
    background: rgba(248, 250, 252, 0.84);
    color: inherit;
    text-decoration: none;
    transition: transform var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
}

body.dark .showcase-file-item {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.03);
}

.showcase-file-icon {
    background: rgba(15, 118, 110, 0.1);
    color: var(--primary);
}

.showcase-file-badge {
    min-height: 28px;
    padding: 5px 10px;
}

.showcase-preview-card {
    grid-column: 2 / span 2;
    justify-content: space-between;
    overflow: hidden;
}

.showcase-preview-art {
    position: relative;
    min-height: 188px;
    border-radius: 24px;
    background:
        radial-gradient(circle at center, rgba(255, 255, 255, 0.16) 0%, rgba(255, 255, 255, 0.04) 36%, transparent 62%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0));
    overflow: hidden;
}

.showcase-preview-ring {
    position: absolute;
    inset: 28px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.14);
}

.showcase-preview-ring-outer {
    animation: dashboardPreviewSpin 18s linear infinite;
}

.showcase-preview-ring-inner {
    inset: 48px;
    animation: dashboardPreviewSpinReverse 14s linear infinite;
}

.showcase-preview-core {
    position: absolute;
    inset: 50%;
    width: 124px;
    height: 124px;
    margin: -62px 0 0 -62px;
    border-radius: 50%;
    background:
        radial-gradient(circle at 36% 34%, rgba(255, 255, 255, 0.9) 0 7%, rgba(255, 255, 255, 0.16) 8% 19%, transparent 20%),
        radial-gradient(circle at center, rgba(255, 255, 255, 0.06) 0 38%, rgba(255, 255, 255, 0.92) 39% 46%, rgba(255, 255, 255, 0.08) 47% 64%, transparent 65%);
    box-shadow: 0 0 60px rgba(255, 255, 255, 0.14);
}

.showcase-preview-copy h2 {
    margin: 4px 0 0;
    font-size: 1.18rem;
    line-height: 1.16;
    letter-spacing: -0.04em;
}

.showcase-preview-copy p {
    margin: 10px 0 0;
    max-width: 520px;
    font-size: 0.82rem;
    line-height: 1.7;
}

.showcase-preview-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.showcase-preview-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: 0.74rem;
    font-weight: 700;
}

.showcase-team-card {
    grid-column: 1 / -1;
}

.showcase-team-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.showcase-team-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.showcase-team-stat {
    min-width: 120px;
    padding: 10px 12px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.08);
}

.showcase-team-stat-top {
    display: flex;
    align-items: center;
    gap: 8px;
}

.showcase-team-stat-icon {
    width: 28px;
    height: 28px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.74rem;
}

.showcase-team-stat-icon.bg-primary {
    background: rgba(15, 118, 110, 0.18);
    color: #6ee7d8;
}

.showcase-team-stat-icon.bg-success {
    background: rgba(21, 128, 61, 0.18);
    color: #86efac;
}

.showcase-team-stat-icon.bg-warning {
    background: rgba(180, 83, 9, 0.18);
    color: #fdba74;
}

.showcase-team-stat-icon.bg-danger {
    background: rgba(180, 35, 24, 0.18);
    color: #fca5a5;
}

.showcase-team-stat-icon.bg-secondary {
    background: rgba(21, 94, 239, 0.18);
    color: #93c5fd;
}

.showcase-team-stat span {
    display: block;
    font-size: 0.7rem;
    color: rgba(226, 232, 240, 0.72);
}

.showcase-team-stat strong {
    display: block;
    margin-top: 5px;
    font-size: 1rem;
    color: #fff;
    letter-spacing: -0.04em;
}

.showcase-team-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.showcase-team-module {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 100%;
    padding: 16px;
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: inherit;
    text-decoration: none;
    transition: transform var(--transition-fast), box-shadow var(--transition-fast), border-color var(--transition-fast);
}

.showcase-team-module-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.showcase-team-module-icon {
    background: rgba(255, 255, 255, 0.08);
    color: var(--module-accent, #fff);
}

.showcase-team-module-badge {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(226, 232, 240, 0.84);
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.showcase-team-module-title {
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.24;
    color: #fff;
    letter-spacing: -0.03em;
}

.showcase-team-module p {
    margin: 0;
    font-size: 0.78rem;
    line-height: 1.65;
}

.showcase-team-module-foot {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px;
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.showcase-team-module-foot strong {
    max-width: 40%;
    font-size: 1.08rem;
    line-height: 1.05;
    color: #fff;
    letter-spacing: -0.05em;
}

.showcase-team-module-foot span {
    max-width: 56%;
    font-size: 0.72rem;
    line-height: 1.5;
    text-align: right;
}

.employee-spotlight-card {
    position: relative;
    display: grid;
    grid-template-columns: minmax(220px, 0.52fr) minmax(0, 1.2fr) minmax(220px, 0.72fr);
    gap: 18px;
    padding: 20px;
    border-radius: 30px;
    border: 1px solid rgba(255, 255, 255, 0.78);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.84), rgba(255, 255, 255, 0.68));
    box-shadow: 0 28px 64px rgba(15, 23, 42, 0.09);
    overflow: hidden;
    isolation: isolate;
}

.employee-spotlight-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 16% 18%, rgba(255, 255, 255, 0.72) 0%, transparent 24%),
        radial-gradient(circle at 88% 24%, rgba(15, 118, 110, 0.1) 0%, transparent 30%);
    pointer-events: none;
    z-index: 0;
}

body.dark .employee-spotlight-card {
    border-color: rgba(173, 191, 215, 0.14);
    background: linear-gradient(180deg, rgba(11, 19, 31, 0.92), rgba(11, 19, 31, 0.8));
    box-shadow: 0 28px 64px rgba(2, 8, 23, 0.34);
}

body.dark .employee-spotlight-card::before {
    background:
        radial-gradient(circle at 16% 18%, rgba(255, 255, 255, 0.06) 0%, transparent 24%),
        radial-gradient(circle at 88% 24%, rgba(15, 118, 110, 0.14) 0%, transparent 30%);
}

.employee-spotlight-media,
.employee-spotlight-content,
.employee-spotlight-side {
    position: relative;
    z-index: 1;
}

.employee-spotlight-media {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100%;
    padding: 18px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.42);
    border: 1px solid rgba(255, 255, 255, 0.5);
    overflow: hidden;
}

body.dark .employee-spotlight-media {
    background: rgba(255, 255, 255, 0.03);
    border-color: rgba(255, 255, 255, 0.06);
}

.employee-spotlight-glow {
    position: absolute;
    border-radius: 999px;
    filter: blur(8px);
    opacity: 0.92;
}

.employee-spotlight-glow-a {
    width: 150px;
    height: 150px;
    top: 8%;
    left: 10%;
    background: rgba(255, 255, 255, 0.8);
}

.employee-spotlight-glow-b {
    width: 120px;
    height: 120px;
    right: 8%;
    bottom: 10%;
    background: rgba(15, 118, 110, 0.14);
}

body.dark .employee-spotlight-glow-a {
    background: rgba(255, 255, 255, 0.08);
}

body.dark .employee-spotlight-glow-b {
    background: rgba(15, 118, 110, 0.18);
}

.employee-spotlight-avatar-shell {
    position: relative;
    width: min(220px, 100%);
    aspect-ratio: 1;
    padding: 10px;
    border-radius: 30px;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.44));
    box-shadow: 0 24px 40px rgba(15, 23, 42, 0.12);
}

body.dark .employee-spotlight-avatar-shell {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.02));
    box-shadow: 0 24px 40px rgba(2, 8, 23, 0.26);
}

.employee-spotlight-avatar,
.employee-spotlight-avatar-fallback {
    width: 100%;
    height: 100%;
    border-radius: 24px;
}

.employee-spotlight-avatar {
    display: block;
    object-fit: cover;
}

.employee-spotlight-avatar-fallback {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--primary), #111827);
    color: #fff;
    font-size: clamp(3rem, 5vw, 4.4rem);
    font-weight: 800;
    letter-spacing: -0.08em;
}

.employee-spotlight-content {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}

.employee-spotlight-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--text-muted);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.employee-spotlight-name {
    margin: 10px 0 0;
    font-size: clamp(1.48rem, 2.3vw, 2.18rem);
    line-height: 1.06;
    letter-spacing: -0.05em;
    color: var(--text);
}

.employee-spotlight-headline {
    margin-top: 8px;
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--primary);
    letter-spacing: -0.02em;
}

.employee-spotlight-summary {
    margin: 12px 0 0;
    max-width: 640px;
    font-size: 0.82rem;
    line-height: 1.72;
    color: var(--text-muted);
}

.employee-spotlight-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}

.employee-spotlight-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid rgba(221, 228, 236, 0.82);
    background: rgba(255, 255, 255, 0.62);
    color: var(--text-2);
    font-size: 0.74rem;
    font-weight: 700;
}

body.dark .employee-spotlight-badge {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.04);
    color: var(--text-2);
}

.employee-spotlight-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 18px;
}

.employee-spotlight-info,
.employee-spotlight-meta-item {
    padding: 12px 14px;
    border-radius: 18px;
    border: 1px solid rgba(221, 228, 236, 0.76);
    background: rgba(255, 255, 255, 0.56);
}

body.dark .employee-spotlight-info,
body.dark .employee-spotlight-meta-item {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.04);
}

.employee-spotlight-info-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.employee-spotlight-info strong,
.employee-spotlight-meta-item strong {
    display: block;
    margin-top: 8px;
    color: var(--text);
    font-size: 0.84rem;
    line-height: 1.5;
    word-break: break-word;
}

.employee-spotlight-side {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px;
    border-radius: 24px;
    background: linear-gradient(180deg, #070d18 0%, #101a2a 56%, #192232 100%);
    color: #eef2ff;
    box-shadow: 0 20px 44px rgba(2, 8, 23, 0.28);
}

.employee-spotlight-side-head {
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: rgba(226, 232, 240, 0.72);
}

.employee-spotlight-meta-list {
    display: grid;
    gap: 10px;
}

.employee-spotlight-meta-item span {
    display: block;
    font-size: 0.68rem;
    color: rgba(226, 232, 240, 0.7);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.employee-spotlight-meta-item strong {
    color: #fff;
    margin-top: 6px;
    font-size: 0.88rem;
}

.employee-spotlight-actions {
    display: grid;
    gap: 10px;
    margin-top: auto;
}

.employee-spotlight-actions .btn {
    width: 100%;
}

.dashboard-metric-strip {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.dashboard-metric-strip .metric-card {
    padding: 18px;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.76);
    border-color: rgba(221, 228, 236, 0.76);
}

body.dark .dashboard-metric-strip .metric-card {
    background: rgba(255, 255, 255, 0.04);
    border-color: rgba(173, 191, 215, 0.12);
}

.dashboard-metric-strip .metric-value {
    margin-top: 12px;
    font-size: 1.46rem;
}

.dashboard-metric-strip .metric-note {
    display: block;
    margin-top: 8px;
    color: var(--text-muted);
    font-size: 0.76rem;
    line-height: 1.6;
}

.dashboard-section-heading p {
    display: block;
}

.shortcut-grid-compact {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(152px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.shortcut-tile-compact {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    padding: 14px;
    border-radius: 20px;
    border: 1px solid rgba(221, 228, 236, 0.74);
    background: rgba(255, 255, 255, 0.8);
    color: var(--text);
    text-align: left;
    text-decoration: none;
    transition: transform var(--transition-fast), border-color var(--transition-fast), box-shadow var(--transition-fast);
    box-shadow: var(--shadow-xs);
    backdrop-filter: blur(14px);
}

body.dark .shortcut-tile-compact {
    border-color: rgba(173, 191, 215, 0.12);
    background: rgba(255, 255, 255, 0.04);
}

.shortcut-tile-compact:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
    border-color: rgba(15, 118, 110, 0.22);
}

.shortcut-tile-compact .icon-wrap {
    width: 38px;
    height: 38px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.94rem;
    background: rgba(15, 118, 110, 0.1);
    color: var(--shortcut-accent);
    transition: transform var(--transition-fast);
    flex-shrink: 0;
}

.shortcut-tile-compact:hover .icon-wrap {
    transform: translateY(-1px);
}

.shortcut-tile-compact .title {
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.4;
    letter-spacing: -0.01em;
}

.chart-container {
    position: relative;
    height: 280px;
    width: 100%;
}

.delegated-task-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.delegated-task-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.88);
    box-shadow: var(--shadow-xs);
}

body.dark .delegated-task-item {
    background: rgba(15, 27, 42, 0.72);
}

.delegated-task-main {
    min-width: 0;
    flex: 1;
}

.delegated-task-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
}

.delegated-task-title {
    font-size: 0.86rem;
    font-weight: 700;
    line-height: 1.35;
    color: var(--text);
}

.delegated-task-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    color: var(--text-muted);
    font-size: 0.76rem;
}

.delegated-task-meta span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.delegated-task-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.delegated-task-actions .btn {
    white-space: nowrap;
}

/* Refined dashboard glass system */
.dashboard-page {
    gap: 18px;
}

.dashboard-showcase,
.employee-spotlight-card {
    backdrop-filter: blur(calc(var(--glass-blur) + 8px));
}

.dashboard-showcase {
    padding: clamp(20px, 2vw, 30px);
    border-radius: 36px;
    border-color: rgba(255, 255, 255, 0.58);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.54), transparent 30%),
        linear-gradient(145deg, rgba(255, 255, 255, 0.68), rgba(255, 255, 255, 0.4));
    box-shadow: var(--shadow-lg);
}

.dashboard-showcase::before {
    width: 260px;
    height: 260px;
    top: -136px;
    right: 2%;
}

.dashboard-showcase::after {
    width: 210px;
    height: 210px;
    bottom: -118px;
    left: 8%;
}

body.dark .dashboard-showcase {
    border-color: rgba(173, 191, 215, 0.12);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.05), transparent 28%),
        linear-gradient(145deg, rgba(11, 19, 31, 0.84), rgba(11, 19, 31, 0.62));
}

.dashboard-showcase-top {
    grid-template-columns: minmax(0, 1.14fr) minmax(300px, 0.78fr);
    gap: 18px;
    margin-bottom: 18px;
}

.dashboard-showcase-title {
    font-size: clamp(1.6rem, 2.3vw, 2.3rem);
    letter-spacing: -0.06em;
}

.dashboard-showcase-description {
    max-width: 560px;
    margin-top: 10px;
    font-size: 0.88rem;
    line-height: 1.68;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.dashboard-showcase-meta {
    gap: 8px;
}

.dashboard-showcase-chip {
    min-height: 36px;
    padding: 7px 12px;
    border-color: rgba(255, 255, 255, 0.52);
    background: rgba(255, 255, 255, 0.42);
    color: var(--text-2);
    font-size: 0.74rem;
}

.dashboard-showcase-toolbar {
    gap: 10px;
}

.dashboard-showcase-search {
    min-height: 50px;
    padding: 12px 14px;
    border-radius: 22px;
    border-color: rgba(255, 255, 255, 0.52);
    background: rgba(255, 255, 255, 0.44);
    box-shadow: none;
}

.dashboard-showcase-search span {
    font-size: 0.78rem;
}

.dashboard-showcase-grid {
    gap: 16px;
}

.showcase-panel {
    gap: 12px;
    padding: 18px;
    border-radius: 26px;
    border-color: rgba(255, 255, 255, 0.54);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.3), transparent 36%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.4));
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(calc(var(--glass-blur) + 4px));
}

body.dark .showcase-panel {
    border-color: rgba(173, 191, 215, 0.12);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
}

.showcase-panel-dark {
    border-color: rgba(255, 255, 255, 0.08);
    background: linear-gradient(160deg, rgba(7, 13, 24, 0.86) 0%, rgba(16, 26, 42, 0.82) 56%, rgba(25, 34, 50, 0.74) 100%);
}

.showcase-panel-note {
    font-size: 0.78rem;
    line-height: 1.65;
}

.showcase-panel-badge,
.showcase-file-badge,
.showcase-timeline-count {
    border: 1px solid rgba(255, 255, 255, 0.48);
    background: rgba(255, 255, 255, 0.5);
    color: var(--text-2);
}

.showcase-panel-dark .showcase-panel-badge,
.showcase-panel-dark .showcase-file-badge,
.showcase-panel-dark .showcase-timeline-count {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.08);
    color: rgba(226, 232, 240, 0.86);
}

.showcase-timeline-item,
.showcase-file-item {
    padding: 12px 13px;
    border-radius: 18px;
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.54);
}

.showcase-timeline-copy strong,
.showcase-file-copy strong {
    font-size: 0.82rem;
}

.showcase-timeline-copy span,
.showcase-file-copy span,
.showcase-preview-copy p,
.showcase-team-module p,
.showcase-team-module-foot span {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.showcase-timeline-copy span,
.showcase-file-copy span {
    -webkit-line-clamp: 2;
}

.showcase-preview-copy p {
    -webkit-line-clamp: 3;
}

.showcase-team-module p,
.showcase-team-module-foot span {
    -webkit-line-clamp: 2;
}

.showcase-progress-ring {
    width: 156px;
}

.showcase-micro-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.showcase-micro-stat {
    padding: 12px;
    border-radius: 16px;
    border-color: rgba(255, 255, 255, 0.46);
    background: rgba(255, 255, 255, 0.42);
}

body.dark .showcase-micro-stat {
    border-color: rgba(173, 191, 215, 0.12);
}

.showcase-preview-art {
    min-height: 170px;
    border-radius: 22px;
}

.showcase-preview-core {
    width: 110px;
    height: 110px;
    margin: -55px 0 0 -55px;
}

.showcase-preview-copy h2 {
    font-size: 1.12rem;
}

.showcase-team-stats {
    gap: 8px;
}

.showcase-team-stat {
    padding: 10px 12px;
    border-radius: 16px;
}

.showcase-team-grid {
    gap: 12px;
}

.showcase-team-module {
    padding: 15px;
    border-radius: 22px;
}

.employee-spotlight-card {
    gap: 16px;
    padding: 18px;
    border-radius: 34px;
    border-color: rgba(255, 255, 255, 0.6);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.46), transparent 28%),
        linear-gradient(145deg, rgba(255, 255, 255, 0.66), rgba(255, 255, 255, 0.42));
    box-shadow: var(--shadow-lg);
}

body.dark .employee-spotlight-card {
    border-color: rgba(173, 191, 215, 0.12);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.05), transparent 28%),
        linear-gradient(145deg, rgba(11, 19, 31, 0.84), rgba(11, 19, 31, 0.66));
}

.employee-spotlight-media {
    padding: 16px;
    border-radius: 26px;
    background: rgba(255, 255, 255, 0.3);
    border-color: rgba(255, 255, 255, 0.46);
}

.employee-spotlight-avatar-shell {
    width: min(208px, 100%);
    padding: 9px;
    border-radius: 28px;
}

.employee-spotlight-summary {
    margin-top: 10px;
    font-size: 0.8rem;
    line-height: 1.68;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.employee-spotlight-badges {
    gap: 8px;
}

.employee-spotlight-badge {
    min-height: 32px;
    padding: 6px 11px;
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.42);
}

.employee-spotlight-grid {
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
}

.employee-spotlight-info,
.employee-spotlight-meta-item {
    padding: 12px 13px;
    border-radius: 18px;
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.44);
}

.employee-spotlight-info strong,
.employee-spotlight-meta-item strong {
    font-size: 0.82rem;
}

.employee-spotlight-side {
    gap: 12px;
    padding: 16px;
    border-radius: 26px;
    background: linear-gradient(180deg, rgba(7, 13, 24, 0.88), rgba(16, 26, 42, 0.76));
    backdrop-filter: blur(calc(var(--glass-blur) + 6px));
}

.dashboard-metric-strip .metric-card {
    padding: 16px;
    border-radius: 22px;
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.28), transparent 34%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.58), rgba(255, 255, 255, 0.42));
}

.dashboard-metric-strip .metric-note,
.dashboard-section-heading p {
    display: none;
}

.shortcut-grid-compact {
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
}

.shortcut-tile-compact {
    padding: 14px 15px;
    border-radius: 20px;
    border-color: rgba(255, 255, 255, 0.52);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.26), transparent 34%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.44));
}

.shortcut-tile-compact .title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.chart-container {
    height: 260px;
}

.delegated-task-item {
    border-color: rgba(255, 255, 255, 0.5);
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.24), transparent 34%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.66), rgba(255, 255, 255, 0.46));
    backdrop-filter: blur(calc(var(--glass-blur) + 2px));
}

body.dark .delegated-task-item {
    background: linear-gradient(180deg, rgba(15, 27, 42, 0.76), rgba(15, 27, 42, 0.58));
}

@keyframes dashboardPreviewSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes dashboardPreviewSpinReverse {
    from { transform: rotate(360deg); }
    to { transform: rotate(0deg); }
}

@media (max-width: 1180px) {
    .dashboard-showcase-top {
        grid-template-columns: 1fr;
    }

    .dashboard-showcase-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .showcase-timeline-card,
    .showcase-preview-card,
    .showcase-team-card {
        grid-column: 1 / -1;
    }

    .employee-spotlight-card {
        grid-template-columns: minmax(220px, 0.56fr) minmax(0, 1fr);
    }

    .employee-spotlight-side {
        grid-column: 1 / -1;
    }
}

@media (max-width: 768px) {
    .dashboard-page {
        gap: 16px;
    }

    .dashboard-showcase {
        padding: 18px;
        border-radius: 24px;
    }

    .dashboard-showcase::before {
        width: 160px;
        height: 160px;
        top: -88px;
        right: -34px;
    }

    .dashboard-showcase::after {
        width: 132px;
        height: 132px;
        bottom: -72px;
        left: -22px;
    }

    .dashboard-showcase-title {
        font-size: clamp(1.34rem, 7vw, 1.86rem);
    }

    .dashboard-showcase-description {
        font-size: 0.84rem;
    }

    .dashboard-showcase-grid {
        grid-template-columns: 1fr;
    }

    .showcase-timeline-card,
    .showcase-preview-card,
    .showcase-team-card {
        grid-column: auto;
    }

    .dashboard-showcase-actions {
        justify-content: stretch;
    }

    .dashboard-showcase-actions .btn {
        flex: 1 1 100%;
    }

    .showcase-progress-ring {
        width: 146px;
    }

    .showcase-file-item,
    .showcase-timeline-item {
        grid-template-columns: auto 1fr;
    }

    .showcase-file-badge,
    .showcase-timeline-count {
        grid-column: 2 / 3;
        justify-self: start;
    }

    .showcase-team-grid {
        grid-template-columns: 1fr;
    }

    .employee-spotlight-card {
        grid-template-columns: 1fr;
        padding: 16px;
        border-radius: 24px;
    }

    .employee-spotlight-media {
        min-height: auto;
        padding: 16px;
    }

    .employee-spotlight-avatar-shell {
        width: min(200px, 72vw);
    }

    .employee-spotlight-grid {
        grid-template-columns: 1fr;
    }

    .employee-spotlight-side {
        padding: 16px;
    }

    .showcase-team-module-foot {
        flex-direction: column;
        align-items: flex-start;
    }

    .showcase-team-module-foot strong,
    .showcase-team-module-foot span {
        max-width: none;
        text-align: left;
    }

    .shortcut-grid-compact {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }

    .delegated-task-item {
        flex-direction: column;
        align-items: stretch;
    }

    .delegated-task-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .delegated-task-actions {
        width: 100%;
    }

    .delegated-task-actions .btn {
        flex: 1;
        justify-content: center;
    }
}

@media (max-width: 520px) {
    .dashboard-showcase-chip {
        width: 100%;
        justify-content: flex-start;
    }

    .showcase-panel,
    .shortcut-tile-compact {
        padding: 14px;
        border-radius: 20px;
    }

    .showcase-preview-art {
        min-height: 152px;
    }

    .showcase-preview-core {
        width: 96px;
        height: 96px;
        margin: -48px 0 0 -48px;
    }

    .showcase-team-stats {
        width: 100%;
    }

    .showcase-team-stat {
        flex: 1 1 calc(50% - 10px);
        min-width: 0;
    }

    .shortcut-grid-compact {
        grid-template-columns: 1fr;
    }

    .employee-spotlight-badges {
        gap: 8px;
    }

    .employee-spotlight-badge {
        width: 100%;
        justify-content: flex-start;
    }
}

@media (max-width: 1180px) {
    .dashboard-showcase-top {
        grid-template-columns: 1fr;
    }

    .dashboard-showcase-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .employee-spotlight-card {
        grid-template-columns: minmax(210px, 0.52fr) minmax(0, 1fr);
    }
}

@media (max-width: 768px) {
    .dashboard-showcase {
        padding: 16px;
        border-radius: 26px;
    }

    .dashboard-showcase-title {
        font-size: clamp(1.28rem, 6.4vw, 1.82rem);
    }

    .dashboard-showcase-description {
        -webkit-line-clamp: 4;
    }

    .showcase-micro-grid {
        grid-template-columns: 1fr;
    }

    .employee-spotlight-summary {
        display: block;
        -webkit-line-clamp: unset;
        overflow: visible;
    }

    .dashboard-metric-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .dashboard-showcase-chip,
    .employee-spotlight-badge {
        width: 100%;
        justify-content: flex-start;
    }

    .dashboard-metric-strip,
    .shortcut-grid-compact {
        grid-template-columns: 1fr;
    }
}
</style>
HTML;
}

function dashboardFetchTransactionStatusCounts(mysqli $conn, string $whereSql = '', string $types = '', ...$params): array
{
    $statuses = ['draft', 'pending', 'dp', 'tempo', 'selesai', 'batal'];
    $counts = array_fill_keys($statuses, 0);
    $sql = "SELECT status, COUNT(*) AS total FROM transaksi";

    if ($whereSql !== '') {
        $sql .= ' WHERE ' . $whereSql;
    }

    $sql .= ' GROUP BY status';

    foreach (schemaFetchAllAssoc($conn, $sql, $types, ...$params) as $row) {
        $status = (string) ($row['status'] ?? '');
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) ($row['total'] ?? 0);
        }
    }

    return $counts;
}

function buildDashboardPageData(mysqli $conn): array
{
    $userRole = $_SESSION['role'] ?? 'user';
    $userName = $_SESSION['nama'] ?? 'User';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $roleLabels = [
        'superadmin' => 'Superadmin',
        'admin' => 'Admin',
        'service' => 'Service',
        'kasir' => 'Kasir',
        'user' => 'User',
    ];

    $roleFocus = [
        'superadmin' => [
            'title' => 'Pantau seluruh operasional dari satu dashboard.',
            'description' => 'Gunakan ringkasan sistem untuk membaca performa transaksi, produksi, file kerja, dan stok tanpa harus berpindah halaman terlalu sering.',
        ],
        'admin' => [
            'title' => 'Jaga ritme transaksi, produksi, dan follow-up harian tetap sinkron.',
            'description' => 'Mulai dari inbox prioritas, lanjut cek audit dan aktivitas produksi, lalu tutup hari kerja dengan ringkasan operasional.',
        ],
        'service' => [
            'title' => 'Dorong handoff file dan produksi agar pekerjaan tidak berhenti di tengah jalan.',
            'description' => 'Pantau job aktif, deadline yang kritis, dan file siap cetak baru supaya koordinasi antar tim tetap mulus.',
        ],
        'kasir' => [
            'title' => 'Percepat checkout dan rapikan order yang sudah sampai di kasir.',
            'description' => 'Gunakan dashboard ini untuk membaca ritme transaksi hari ini dan menuntaskan order yang sudah menunggu pembayaran.',
        ],
        'user' => [
            'title' => 'Masuk langsung ke pekerjaan pribadi yang perlu Anda selesaikan.',
            'description' => 'Dashboard ini menyorot job yang ditugaskan ke Anda, tahapan kerja yang belum selesai, dan status absensi hari ini.',
        ],
    ];

    $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $monthNames = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $todayLabel = $dayNames[(int) date('w')] . ', ' . date('j') . ' ' . $monthNames[(int) date('n')] . ' ' . date('Y');

    $shortcuts = [
        ['pos.php', 'POS / Kasir', 'fa-cash-register', '#10b981', ['superadmin', 'admin', 'service', 'kasir'], 'Mulai transaksi baru dan checkout lebih cepat.'],
        ['transaksi.php', 'Data Transaksi', 'fa-receipt', '#3b82f6', ['superadmin', 'admin', 'kasir'], 'Pantau invoice, pembayaran, dan lampiran pelanggan.'],
        ['produksi.php', 'Produksi', 'fa-industry', '#f59e0b', ['superadmin', 'admin', 'service', 'user'], 'Lihat JO/SPK, progres, dan penugasan tim.'],
        ['siap_cetak.php', 'File Siap Cetak', 'fa-print', '#8b5cf6', ['superadmin', 'admin', 'service', 'user'], 'Cek file final sebelum masuk ke proses cetak.'],
        ['absensi_mobile.php', 'Absensi Saya', 'fa-user-clock', '#ec4899', ['superadmin', 'admin', 'service', 'kasir', 'user'], 'Catat kehadiran langsung dari perangkat aktif.'],
        ['chat.php', 'Room Chat', 'fa-comments', '#f43f5e', ['superadmin', 'admin', 'service', 'kasir', 'user'], 'Koordinasi cepat dengan tim operasional.'],
        ['produk.php', 'Produk & Stok', 'fa-boxes', '#06b6d4', ['superadmin', 'admin', 'service'], 'Review stok, harga, dan katalog jual.'],
        ['finishing.php', 'Finishing & Bahan', 'fa-layer-group', '#eab308', ['superadmin', 'admin'], 'Atur opsi finishing dan bahan apparel.'],
        ['pelanggan.php', 'Pelanggan', 'fa-users', '#14b8a6', ['superadmin', 'admin', 'service', 'kasir'], 'Kelola data pelanggan dan status mitra.'],
        ['absensi.php', 'Data Absensi', 'fa-calendar-check', '#ef4444', ['superadmin', 'admin'], 'Monitor absensi seluruh tim kerja.'],
        ['karyawan.php', 'Karyawan', 'fa-id-badge', '#6366f1', ['superadmin', 'admin'], 'Kelola profil, jabatan, dan status karyawan.'],
        ['penggajian.php', 'Penggajian', 'fa-file-invoice-dollar', '#10b981', ['superadmin', 'admin'], 'Proses penggajian dan slip karyawan.'],
        ['kpi.php', 'KPI Kinerja', 'fa-chart-line', '#8b5cf6', ['superadmin', 'admin'], 'Pantau target dan capaian kerja.'],
        ['operasional.php', 'Pengeluaran', 'fa-wallet', '#f43f5e', ['superadmin', 'admin'], 'Catat biaya operasional dan kontrol cashflow.'],
        ['laporan.php', 'Laporan', 'fa-chart-pie', '#f59e0b', ['superadmin', 'admin'], 'Buka rekap transaksi, produksi, dan performa.'],
        ['setting.php', 'Pengaturan', 'fa-cog', '#64748b', ['superadmin'], 'Atur sistem, pajak, tema, dan permission.'],
        ['setting.php#backup-restore-card', 'Backup / Restore', 'fa-database', '#0f766e', ['superadmin'], 'Amankan data dan kelola pemulihan sistem.'],
    ];

    $visibleShortcuts = array_values(array_filter($shortcuts, static function (array $shortcut) use ($userRole): bool {
        return in_array($userRole, $shortcut[4], true);
    }));
    $heroActions = array_slice($visibleShortcuts, 0, 2);

    $notificationItems = getNotificationItems(6);
    $notificationCount = getNotificationCount();
    $currentEmployee = getCurrentEmployeeProfile();
    $employeeId = (int) ($currentEmployee['id'] ?? 0);
    $payableAlertSummary = function_exists('materialInventoryFetchPayableAlertSummary')
        ? materialInventoryFetchPayableAlertSummary($conn, 7)
        : [
            'active' => ['count' => 0, 'total' => 0],
            'overdue' => ['count' => 0, 'total' => 0],
            'due_soon' => ['count' => 0, 'total' => 0],
        ];
    $supplierPayableCount = (int) (($payableAlertSummary['active']['count'] ?? 0));
    $supplierPayableTotal = (float) (($payableAlertSummary['active']['total'] ?? 0));
    $supplierPayableOverdue = (int) (($payableAlertSummary['overdue']['count'] ?? 0));
    $supplierPayableDueSoon = (int) (($payableAlertSummary['due_soon']['count'] ?? 0));

    $metricCards = [];
    $adminSummaryCards = [];
    $recentTransactions = [];
    $lowStock = [];
    $recentAudit = [];
    $serviceProduksiRows = [];
    $serviceReadyPrintRows = [];
    $kasirRecentRows = [];
    $userJobs = [];
    $attendanceToday = null;
    $myPendingStages = 0;
    $myDoneStagesToday = 0;
    $myAttendanceLabel = 'Belum absen';
    $myAttendanceNote = 'Belum ada catatan absensi untuk hari ini.';
    $supportsDelegatedStageInbox = in_array($userRole, ['service', 'kasir', 'user'], true);
    $assignedStageStats = ['total' => 0, 'overdue' => 0];
    $assignedStageRows = [];
    $chartPrimary = [
        'title' => 'Aktivitas 7 hari',
        'label' => 'Aktivitas',
        'labels' => [],
        'data' => [],
        'color' => '#0f766e',
        'fill' => 'rgba(15, 118, 110, 0.12)',
        'format' => 'number',
    ];
    $chartBreakdown = [
        'title' => 'Komposisi',
        'labels' => [],
        'data' => [],
        'colors' => ['#94a3b8', '#f59e0b', '#3b82f6', '#64748b', '#10b981', '#ef4444'],
    ];

    if ($supportsDelegatedStageInbox) {
        $assignedStageStats = fetchAssignedStageStats($userId);
        $assignedStageRows = fetchAssignedStageRows($userId, 6);
    }

    $myAssignedStageCount = (int) ($assignedStageStats['total'] ?? 0);
    $myOverdueAssignedStages = (int) ($assignedStageStats['overdue'] ?? 0);

    if (in_array($userRole, ['superadmin', 'admin'], true)) {
        $cashierQueue = dashboardTransactionCashierQueueCount($conn);
        $overdueProduksi = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN ('selesai', 'batal')");
        $readyPrintRecent = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM file_transaksi WHERE tipe_file = 'siap_cetak' AND is_active = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $lowStockCount = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produk WHERE stok <= 5");

        $metricCards = [
            ['label' => 'Antrian Aktif', 'value' => number_format($notificationCount), 'note' => 'Total item prioritas dari notification center.'],
            ['label' => 'Menunggu Kasir', 'value' => number_format($cashierQueue), 'note' => 'Order yang sudah siap dibayar atau perlu penutupan di meja kasir.'],
            ['label' => 'Deadline Kritis', 'value' => number_format($overdueProduksi), 'note' => 'Job produksi yang sudah melewati target pengerjaan.'],
            ['label' => 'Stok Minimum', 'value' => number_format($lowStockCount), 'note' => 'Produk yang perlu dipantau agar operasional tidak tersendat.'],
            [
                'label' => 'Tempo Supplier',
                'value' => number_format($supplierPayableOverdue + $supplierPayableDueSoon),
                'note' => $supplierPayableOverdue > 0
                    ? $supplierPayableOverdue . ' sudah lewat tempo, ' . $supplierPayableDueSoon . ' jatuh tempo <= 7 hari.'
                    : ($supplierPayableDueSoon > 0
                        ? $supplierPayableDueSoon . ' hutang supplier perlu diantisipasi sebelum jatuh tempo.'
                        : 'Belum ada hutang supplier jatuh tempo dekat saat ini.'),
            ],
        ];

        $totalPelanggan = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM pelanggan");
        $totalProduk = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produk");
        $totalTrxHari = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE DATE(created_at) = CURDATE()");
        $omzetHari = (float) schemaFetchScalar($conn, "SELECT COALESCE(SUM(total), 0) FROM transaksi WHERE status = 'selesai' AND DATE(created_at) = CURDATE()");
        $totalOperasional = (float) schemaFetchScalar($conn, "SELECT COALESCE(SUM(jumlah), 0) FROM operasional WHERE MONTH(tanggal) = MONTH(CURDATE()) AND YEAR(tanggal) = YEAR(CURDATE())");

        $adminSummaryCards = [
            ['icon' => 'fas fa-receipt', 'class' => 'bg-primary', 'value' => number_format($totalTrxHari), 'label' => 'Transaksi Hari Ini'],
            ['icon' => 'fas fa-money-bill-wave', 'class' => 'bg-success', 'value' => number_format($omzetHari, 0, ',', '.'), 'label' => 'Omzet Selesai Hari Ini', 'rp' => true],
            ['icon' => 'fas fa-wallet', 'class' => 'bg-warning', 'value' => number_format($totalOperasional, 0, ',', '.'), 'label' => 'Pengeluaran Bulan Ini', 'rp' => true],
            ['icon' => 'fas fa-file-invoice-dollar', 'class' => $supplierPayableOverdue > 0 ? 'bg-danger' : 'bg-warning', 'value' => number_format($supplierPayableTotal, 0, ',', '.'), 'label' => 'Hutang Supplier Aktif', 'rp' => true],
            ['icon' => 'fas fa-users', 'class' => 'bg-secondary', 'value' => number_format($totalPelanggan), 'label' => 'Total Pelanggan'],
            ['icon' => 'fas fa-boxes', 'class' => 'bg-danger', 'value' => number_format($totalProduk), 'label' => 'Total Produk'],
            ['icon' => 'fas fa-calendar-xmark', 'class' => $supplierPayableOverdue > 0 ? 'bg-danger' : 'bg-secondary', 'value' => number_format($supplierPayableOverdue), 'label' => 'Supplier Lewat Tempo'],
            ['icon' => 'fas fa-print', 'class' => 'bg-secondary', 'value' => number_format($readyPrintRecent), 'label' => 'File Siap Cetak Baru'],
        ];

        $recentTransactions = schemaFetchAllAssoc($conn, "SELECT t.no_transaksi, t.total, t.status, t.created_at, p.nama AS nama_pelanggan
            FROM transaksi t
            LEFT JOIN pelanggan p ON t.pelanggan_id = p.id
            ORDER BY t.created_at DESC
            LIMIT 5");
        $lowStock = schemaFetchAllAssoc($conn, "SELECT nama, stok, satuan FROM produk WHERE stok <= 5 ORDER BY stok ASC LIMIT 5");
        $recentAudit = fetchRecentAuditLogs(5);

        $chartPrimary = [
            'title' => 'Tren omzet 7 hari',
            'label' => 'Omzet selesai',
            'labels' => [],
            'data' => [],
            'color' => '#2563eb',
            'fill' => 'rgba(37, 99, 235, 0.12)',
            'format' => 'currency',
        ];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartPrimary['labels'][] = date('d M', strtotime("-{$i} days"));
            $chartPrimary['data'][] = (float) schemaFetchScalar($conn, "SELECT COALESCE(SUM(total), 0) FROM transaksi WHERE status = 'selesai' AND DATE(created_at) = '{$date}'");
        }

        $transactionStatusCounts = dashboardFetchTransactionStatusCounts($conn);
        $chartBreakdown = [
            'title' => 'Komposisi transaksi',
            'labels' => ['Draft', 'Pending', 'DP', 'Tempo', 'Selesai', 'Batal'],
            'data' => [
                $transactionStatusCounts['draft'],
                $transactionStatusCounts['pending'],
                $transactionStatusCounts['dp'],
                $transactionStatusCounts['tempo'],
                $transactionStatusCounts['selesai'],
                $transactionStatusCounts['batal'],
            ],
            'colors' => ['#94a3b8', '#f59e0b', '#3b82f6', '#64748b', '#10b981', '#ef4444'],
        ];
    } elseif ($userRole === 'service') {
        $activeProduksi = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE status IN ('antrian', 'proses')");
        $overdueProduksi = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN ('selesai', 'batal')");
        $readyPrintRecent = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM file_transaksi WHERE tipe_file = 'siap_cetak' AND is_active = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)");
        $unassignedJobs = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE status IN ('antrian', 'proses') AND karyawan_id IS NULL");

        $metricCards = [
            ['label' => 'Antrian Aktif', 'value' => number_format($notificationCount), 'note' => 'Item prioritas yang perlu Anda dorong hari ini.'],
            ['label' => 'Job Aktif', 'value' => number_format($activeProduksi), 'note' => 'JO/SPK yang sedang menunggu follow-up produksi.'],
            ['label' => 'Deadline Kritis', 'value' => number_format($overdueProduksi), 'note' => 'Prioritas yang berisiko telat jika tidak segera ditindaklanjuti.'],
            ['label' => 'File Siap Cetak', 'value' => number_format($readyPrintRecent), 'note' => 'File final baru yang siap dicek untuk handoff berikutnya.'],
        ];

        $serviceProduksiRows = schemaFetchAllAssoc($conn, "SELECT pr.no_dokumen, pr.nama_pekerjaan, pr.deadline, pr.status, t.no_transaksi, k.nama AS nama_karyawan
            FROM produksi pr
            LEFT JOIN transaksi t ON pr.transaksi_id = t.id
            LEFT JOIN karyawan k ON pr.karyawan_id = k.id
            WHERE pr.status IN ('antrian', 'proses')
            ORDER BY CASE WHEN pr.deadline IS NULL THEN 1 ELSE 0 END, pr.deadline ASC, pr.created_at DESC
            LIMIT 5");

        $serviceReadyPrintRows = schemaFetchAllAssoc($conn, "SELECT f.nama_asli, f.created_at, t.no_transaksi, u.nama AS nama_uploader
            FROM file_transaksi f
            LEFT JOIN transaksi t ON f.transaksi_id = t.id
            LEFT JOIN users u ON f.uploaded_by = u.id
            WHERE f.tipe_file = 'siap_cetak' AND f.is_active = 1
            ORDER BY f.created_at DESC
            LIMIT 5");

        if ($unassignedJobs > 0 && empty($notificationItems)) {
            $notificationItems[] = [
                'icon' => 'fa-user-plus',
                'tone' => 'info',
                'count' => $unassignedJobs,
                'title' => $unassignedJobs . ' job belum punya PIC',
                'message' => 'Tentukan penanggung jawab agar handoff produksi tidak terhenti.',
                'href' => 'produksi.php?progress=belum',
            ];
        }

        $chartPrimary = [
            'title' => 'Handoff file 7 hari',
            'label' => 'File siap cetak',
            'labels' => [],
            'data' => [],
            'color' => '#8b5cf6',
            'fill' => 'rgba(139, 92, 246, 0.14)',
            'format' => 'number',
        ];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartPrimary['labels'][] = date('d M', strtotime("-{$i} days"));
            $chartPrimary['data'][] = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM file_transaksi WHERE tipe_file = 'siap_cetak' AND is_active = 1 AND DATE(created_at) = '{$date}'");
        }

        $chartBreakdown = [
            'title' => 'Fokus service',
            'labels' => ['Job aktif', 'Deadline kritis', 'Siap cetak baru', 'Belum ada PIC'],
            'data' => [$activeProduksi, $overdueProduksi, $readyPrintRecent, $unassignedJobs],
            'colors' => ['#0f766e', '#ef4444', '#8b5cf6', '#f59e0b'],
        ];
    } elseif ($userRole === 'kasir') {
        $kasirTodayCount = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE user_id = ? AND DATE(created_at) = CURDATE()", 'i', $userId);
        $kasirCashierQueue = dashboardTransactionCashierQueueCount($conn);
        $kasirCompletedToday = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE user_id = ? AND status = 'selesai' AND DATE(created_at) = CURDATE()", 'i', $userId);
        $kasirOmzetToday = (float) schemaFetchScalar($conn, "SELECT COALESCE(SUM(total), 0) FROM transaksi WHERE user_id = ? AND status = 'selesai' AND DATE(created_at) = CURDATE()", 'i', $userId);

        $metricCards = [
            ['label' => 'Transaksi Hari Ini', 'value' => number_format($kasirTodayCount), 'note' => 'Jumlah transaksi yang Anda input pada hari ini.'],
            ['label' => 'Antrian Kasir', 'value' => number_format($kasirCashierQueue), 'note' => 'Order lintas tim yang sudah siap ditangani di meja kasir.'],
            ['label' => 'Selesai Hari Ini', 'value' => number_format($kasirCompletedToday), 'note' => 'Transaksi Anda yang sudah tuntas hari ini.'],
            ['label' => 'Omzet Selesai', 'value' => 'Rp ' . number_format($kasirOmzetToday, 0, ',', '.'), 'note' => 'Akumulasi omzet selesai dari transaksi Anda hari ini.'],
        ];

        $kasirRecentRows = schemaFetchAllAssoc($conn, "SELECT no_transaksi, total, bayar, kembalian, status, created_at
            FROM transaksi
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 6", 'i', $userId);

        $chartPrimary = [
            'title' => 'Transaksi saya 7 hari',
            'label' => 'Transaksi masuk',
            'labels' => [],
            'data' => [],
            'color' => '#10b981',
            'fill' => 'rgba(16, 185, 129, 0.14)',
            'format' => 'number',
        ];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartPrimary['labels'][] = date('d M', strtotime("-{$i} days"));
            $chartPrimary['data'][] = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM transaksi WHERE user_id = ? AND DATE(created_at) = ?", 'is', $userId, $date);
        }

        $kasirStatusCounts = dashboardFetchTransactionStatusCounts($conn, 'user_id = ?', 'i', $userId);
        $chartBreakdown = [
            'title' => 'Status transaksi saya',
            'labels' => ['Draft', 'Pending', 'DP', 'Tempo', 'Selesai', 'Batal'],
            'data' => [
                $kasirStatusCounts['draft'],
                $kasirStatusCounts['pending'],
                $kasirStatusCounts['dp'],
                $kasirStatusCounts['tempo'],
                $kasirStatusCounts['selesai'],
                $kasirStatusCounts['batal'],
            ],
            'colors' => ['#94a3b8', '#f59e0b', '#3b82f6', '#64748b', '#10b981', '#ef4444'],
        ];
    } else {
        $myActiveJobs = $employeeId > 0 ? (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE karyawan_id = ? AND status IN ('antrian', 'proses')", 'i', $employeeId) : 0;
        $myOverdueJobs = $employeeId > 0 ? (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM produksi WHERE karyawan_id = ? AND deadline IS NOT NULL AND deadline < CURDATE() AND status NOT IN ('selesai', 'batal')", 'i', $employeeId) : 0;
        $myPendingStages = $myAssignedStageCount;
        $myDoneStagesToday = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM todo_list_tahapan WHERE selesai_oleh = ? AND DATE(selesai_at) = CURDATE()", 'i', $userId);
        $attendanceRows = schemaFetchAllAssoc($conn, "SELECT status, jam_masuk, jam_keluar FROM absensi WHERE user_id = ? AND tanggal = CURDATE() LIMIT 1", 'i', $userId);
        $attendanceToday = $attendanceRows[0] ?? null;

        if ($attendanceToday) {
            $myAttendanceLabel = 'Sudah absen';
            $myAttendanceNote = 'Masuk: ' . (!empty($attendanceToday['jam_masuk']) ? date('H:i', strtotime($attendanceToday['jam_masuk'])) : '-') . ' | Keluar: ' . (!empty($attendanceToday['jam_keluar']) ? date('H:i', strtotime($attendanceToday['jam_keluar'])) : '-');
        }

        $metricCards = [
            ['label' => 'Job Saya', 'value' => number_format($myActiveJobs), 'note' => 'Pekerjaan aktif yang ditugaskan langsung kepada Anda.'],
            ['label' => 'Deadline Kritis', 'value' => number_format($myOverdueJobs), 'note' => 'Pekerjaan pribadi yang sudah melewati deadline.'],
            ['label' => 'Tahapan Belum', 'value' => number_format($myPendingStages), 'note' => 'Tahapan kerja yang masih menunggu Anda selesaikan.'],
            ['label' => 'Absensi Hari Ini', 'value' => $myAttendanceLabel, 'note' => $myAttendanceNote],
        ];

        if ($employeeId > 0) {
            $userJobs = schemaFetchAllAssoc($conn, "SELECT pr.no_dokumen, pr.nama_pekerjaan, pr.deadline, pr.status, t.no_transaksi
                FROM produksi pr
                LEFT JOIN transaksi t ON pr.transaksi_id = t.id
                WHERE pr.karyawan_id = ? AND pr.status IN ('antrian', 'proses')
                ORDER BY CASE WHEN pr.deadline IS NULL THEN 1 ELSE 0 END, pr.deadline ASC, pr.created_at DESC
                LIMIT 5", 'i', $employeeId);
        }

        $chartPrimary = [
            'title' => 'Progres tugas 7 hari',
            'label' => 'Tahapan selesai',
            'labels' => [],
            'data' => [],
            'color' => '#f59e0b',
            'fill' => 'rgba(245, 158, 11, 0.14)',
            'format' => 'number',
        ];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartPrimary['labels'][] = date('d M', strtotime("-{$i} days"));
            $chartPrimary['data'][] = (int) schemaFetchScalar($conn, "SELECT COUNT(*) FROM todo_list_tahapan WHERE selesai_oleh = ? AND DATE(selesai_at) = ?", 'is', $userId, $date);
        }

        $chartBreakdown = [
            'title' => 'Fokus kerja pribadi',
            'labels' => ['Job aktif', 'Deadline kritis', 'Tahapan belum', 'Selesai hari ini'],
            'data' => [$myActiveJobs, $myOverdueJobs, $myPendingStages, $myDoneStagesToday],
            'colors' => ['#0f766e', '#ef4444', '#64748b', '#10b981'],
        ];
    }

    return [
        'userRole' => $userRole,
        'userName' => $userName,
        'userId' => $userId,
        'roleLabels' => $roleLabels,
        'roleFocus' => $roleFocus,
        'todayLabel' => $todayLabel,
        'visibleShortcuts' => $visibleShortcuts,
        'heroActions' => $heroActions,
        'notificationItems' => $notificationItems,
        'notificationCount' => $notificationCount,
        'currentEmployee' => $currentEmployee,
        'employeeId' => $employeeId,
        'metricCards' => $metricCards,
        'adminSummaryCards' => $adminSummaryCards,
        'recentTransactions' => $recentTransactions,
        'lowStock' => $lowStock,
        'recentAudit' => $recentAudit,
        'serviceProduksiRows' => $serviceProduksiRows,
        'serviceReadyPrintRows' => $serviceReadyPrintRows,
        'kasirRecentRows' => $kasirRecentRows,
        'userJobs' => $userJobs,
        'attendanceToday' => $attendanceToday,
        'myPendingStages' => $myPendingStages,
        'myDoneStagesToday' => $myDoneStagesToday,
        'myAttendanceLabel' => $myAttendanceLabel,
        'myAttendanceNote' => $myAttendanceNote,
        'supportsDelegatedStageInbox' => $supportsDelegatedStageInbox,
        'assignedStageStats' => $assignedStageStats,
        'assignedStageRows' => $assignedStageRows,
        'myAssignedStageCount' => $myAssignedStageCount,
        'myOverdueAssignedStages' => $myOverdueAssignedStages,
        'chartPrimary' => $chartPrimary,
        'chartBreakdown' => $chartBreakdown,
        'extraCss' => buildDashboardExtraCss(),
        'pageState' => [
            'dashboardCharts' => [
                'primary' => $chartPrimary,
                'breakdown' => $chartBreakdown,
            ],
        ],
        'pageScriptUrls' => [
            'https://cdn.jsdelivr.net/npm/chart.js',
        ],
        'pageJs' => 'dashboard.js',
    ];
}
