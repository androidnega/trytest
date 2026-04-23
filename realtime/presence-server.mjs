/**
 * Optional live presence for Trytest (WebSocket).
 *
 * Run: cd realtime && npm install && node presence-server.mjs
 * Default port 3457. Set PORT=3457 TRYTEST_PRESENCE_ORIGIN=https://trytest.manuelcode.info
 * (origin is only echoed to clients for debugging).
 *
 * Reverse-proxy wss → this port. Set config/app.php `presence_ws_url` to the public wss URL
 * and the same value in env TRYTEST_PRESENCE_WS for PHP pages.
 *
 * Protocol:
 * - From quiz page: send {"type":"quiz","quizId":123} once after connect.
 * - From admin: send {"type":"admin"} once after connect.
 * - Server pushes {"type":"live_quiz_count","n":7} to all admin clients when the count changes.
 */

import { WebSocketServer } from 'ws';

const port = parseInt(process.env.PORT || '3457', 10);
const quizSockets = new Set();
const adminSockets = new Set();

function liveCount() {
    return quizSockets.size;
}

function broadcast() {
    const n = liveCount();
    const payload = JSON.stringify({ type: 'live_quiz_count', n });
    for (const c of adminSockets) {
        if (c.readyState === 1) {
            c.send(payload);
        }
    }
}

const wss = new WebSocketServer({ port });

wss.on('connection', (ws) => {
    ws.trytestRole = null;
    ws.on('message', (buf) => {
        let msg;
        try {
            msg = JSON.parse(String(buf));
        } catch {
            return;
        }
        if (!msg || typeof msg !== 'object') {
            return;
        }
        if (msg.type === 'admin' && ws.trytestRole === null) {
            ws.trytestRole = 'admin';
            adminSockets.add(ws);
            ws.send(JSON.stringify({ type: 'live_quiz_count', n: liveCount() }));
            return;
        }
        if (msg.type === 'quiz' && ws.trytestRole === null) {
            ws.trytestRole = 'quiz';
            quizSockets.add(ws);
            broadcast();
        }
    });
    ws.on('close', () => {
        if (ws.trytestRole === 'quiz') {
            quizSockets.delete(ws);
            broadcast();
        }
        if (ws.trytestRole === 'admin') {
            adminSockets.delete(ws);
        }
    });
});

// eslint-disable-next-line no-console
console.log('Trytest presence WebSocket on port', port);
