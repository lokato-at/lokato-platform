import dotenv from 'dotenv';
import express from 'express';
import mqtt from 'mqtt';
import mysql from 'mysql2/promise';
import { WebSocketServer } from 'ws';
import crypto from 'node:crypto';
import http from 'node:http';

dotenv.config();

const PORT = Number(process.env.PORT || 8081);
const MQTT_URL = process.env.MQTT_URL || 'mqtt://mqtt:1883';
const MQTT_TOPIC_SCAN = process.env.MQTT_TOPIC_SCAN || '/api/v1/scan';
const MQTT_QOS = Number(process.env.MQTT_QOS || 0);
const MQTT_CLIENT_ID = `${process.env.MQTT_CLIENT_ID || 'lokato-realtime'}-${process.pid}`;
const MQTT_MAX_PAYLOAD_BYTES = Number(process.env.MQTT_MAX_PAYLOAD_BYTES || 4096);

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'db',
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || 'admin',
  password: process.env.DB_PASSWORD || 'admin',
  database: process.env.DB_DATABASE || 'lokato_db',
  waitForConnections: true,
  connectionLimit: Number(process.env.DB_POOL_LIMIT || 10),
  timezone: 'Z',
});

const app = express();
const server = http.createServer(app);
const wss = new WebSocketServer({ server, path: '/ws' });

let mqttConnected = false;

function sendEvent(event, data) {
  const message = JSON.stringify({ event, data });

  wss.clients.forEach((client) => {
    if (client.readyState === client.OPEN) {
      client.send(message);
    }
  });
}

wss.on('connection', (socket) => {
  socket.send(JSON.stringify({ event: 'stream.ready', data: { connected_at: new Date().toISOString() } }));
});

app.get('/health', async (_req, res) => {
  try {
    await pool.query('SELECT 1');
    res.json({ status: 'ok', mqtt_connected: mqttConnected, ws_clients: wss.clients.size });
  } catch (error) {
    res.status(500).json({ status: 'error', mqtt_connected: mqttConnected, error: String(error) });
  }
});

function parseEventTime(rawValue) {
  if (!rawValue || typeof rawValue !== 'string') {
    return new Date();
  }

  const parsed = new Date(rawValue);
  if (Number.isNaN(parsed.getTime())) {
    return new Date();
  }

  return parsed;
}

function parsePayload(rawBuffer) {
  if (rawBuffer.length === 0 || rawBuffer.length > MQTT_MAX_PAYLOAD_BYTES) {
    return null;
  }

  try {
    const parsed = JSON.parse(rawBuffer.toString('utf8'));
    if (!parsed || typeof parsed !== 'object') {
      return null;
    }

    const deviceKey = typeof parsed.device_key === 'string' ? parsed.device_key.trim() : '';
    const trackerUid = typeof parsed.tracker_uid === 'string' ? parsed.tracker_uid.trim() : '';

    if (!/^[A-Za-z0-9_-]{1,100}$/.test(deviceKey)) {
      return null;
    }

    if (!/^[A-Za-z0-9_-]{1,100}$/.test(trackerUid)) {
      return null;
    }

    return {
      deviceKey,
      trackerUid,
      eventTime: parseEventTime(parsed.event_time),
    };
  } catch {
    return null;
  }
}

async function loadRoomSnapshot(connection, roomId) {
  const [rooms] = await connection.query(
    'SELECT id, name FROM rooms WHERE id = ? LIMIT 1',
    [roomId],
  );

  if (!rooms.length) {
    return null;
  }

  const room = rooms[0];

  const [childrenRows] = await connection.query(
    `SELECT c.id, c.name, c.photo_url, cl.updated_at
     FROM child_locations cl
     INNER JOIN children c ON c.id = cl.child_id
     WHERE cl.room_id = ?
     ORDER BY c.name`,
    [roomId],
  );

  const children = childrenRows.map((row) => ({
    child_id: Number(row.id),
    id: Number(row.id),
    name: row.name,
    photo_url: row.photo_url,
    updated_at: row.updated_at ? new Date(row.updated_at).toISOString() : null,
  }));

  return {
    room_id: Number(room.id),
    room_name: room.name,
    current_count: children.length,
    children,
  };
}

async function ingestScan({ topic, deviceKey, trackerUid, eventTime }) {
  const connection = await pool.getConnection();

  try {
    await connection.beginTransaction();

    const eventTimeIso = eventTime.toISOString();
    const eventHash = crypto
      .createHash('sha256')
      .update(`${deviceKey}|${trackerUid}|${eventTimeIso}`)
      .digest('hex');

    const [insertResult] = await connection.query(
      `INSERT INTO ingested_events (event_hash, topic, device_key, tracker_uid, event_time)
       VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE id = id`,
      [eventHash, topic, deviceKey, trackerUid, eventTimeIso],
    );

    if (insertResult.affectedRows === 0) {
      await connection.rollback();
      return { duplicate: true };
    }

    const [devices] = await connection.query(
      'SELECT id, device_key, room_id FROM devices WHERE device_key = ? LIMIT 1',
      [deviceKey],
    );
    if (!devices.length) {
      await connection.rollback();
      return { ignored: 'unknown_device' };
    }

    const device = devices[0];

    const [children] = await connection.query(
      'SELECT id, tracker_uid FROM children WHERE tracker_uid = ? LIMIT 1',
      [trackerUid],
    );
    if (!children.length) {
      await connection.rollback();
      return { ignored: 'unknown_child' };
    }

    const child = children[0];

    const [currentRows] = await connection.query(
      'SELECT child_id, room_id, updated_at FROM child_locations WHERE child_id = ? LIMIT 1 FOR UPDATE',
      [child.id],
    );

    const currentLocation = currentRows[0] || null;
    const fromRoomId = currentLocation ? currentLocation.room_id : null;
    const toRoomId = device.room_id;

    const [movementInsert] = await connection.query(
      `INSERT INTO movement_log (child_id, from_room_id, to_room_id, device_id, source, occurred_at)
       VALUES (?, ?, ?, ?, 'mqtt_scanner', ?)`,
      [child.id, fromRoomId, toRoomId, device.id, eventTimeIso],
    );

    if (!currentLocation) {
      await connection.query(
        'INSERT INTO child_locations (child_id, room_id, updated_at) VALUES (?, ?, ?)',
        [child.id, toRoomId, eventTimeIso],
      );
    } else if (new Date(eventTimeIso).getTime() >= new Date(currentLocation.updated_at).getTime()) {
      await connection.query(
        'UPDATE child_locations SET room_id = ?, updated_at = ? WHERE child_id = ?',
        [toRoomId, eventTimeIso, child.id],
      );
    }

    await connection.query('UPDATE devices SET last_seen = NOW() WHERE id = ?', [device.id]);

    const [childRows] = await connection.query('SELECT id, name FROM children WHERE id = ? LIMIT 1', [child.id]);
    const [fromRoomRows] = fromRoomId
      ? await connection.query('SELECT id, name FROM rooms WHERE id = ? LIMIT 1', [fromRoomId])
      : [[]];
    const [toRoomRows] = toRoomId
      ? await connection.query('SELECT id, name FROM rooms WHERE id = ? LIMIT 1', [toRoomId])
      : [[]];

    const movementEvent = {
      id: Number(movementInsert.insertId),
      child_id: Number(child.id),
      child: childRows[0] ? { id: Number(childRows[0].id), name: childRows[0].name } : null,
      from_room_id: fromRoomId ? Number(fromRoomId) : null,
      from_room: fromRoomRows[0] ? { id: Number(fromRoomRows[0].id), name: fromRoomRows[0].name } : null,
      to_room_id: toRoomId ? Number(toRoomId) : null,
      to_room: toRoomRows[0] ? { id: Number(toRoomRows[0].id), name: toRoomRows[0].name } : null,
      device_id: Number(device.id),
      source: 'mqtt_scanner',
      occurred_at: eventTimeIso,
    };

    const changedRoomIds = [fromRoomId, toRoomId].filter((roomId, idx, all) => roomId && all.indexOf(roomId) === idx);
    const snapshots = [];

    for (const roomId of changedRoomIds) {
      const snapshot = await loadRoomSnapshot(connection, roomId);
      if (snapshot) snapshots.push(snapshot);
    }

    await connection.commit();

    return {
      duplicate: false,
      movementEvent,
      snapshots,
    };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

const mqttClient = mqtt.connect(MQTT_URL, {
  clientId: MQTT_CLIENT_ID,
  reconnectPeriod: 2000,
});

mqttClient.on('connect', () => {
  mqttConnected = true;
  console.log(`[realtime] MQTT connected (${MQTT_URL})`);
  mqttClient.subscribe(MQTT_TOPIC_SCAN, { qos: MQTT_QOS }, (err) => {
    if (err) {
      console.error('[realtime] subscribe failed', err);
      return;
    }

    console.log(`[realtime] subscribed to ${MQTT_TOPIC_SCAN} (qos=${MQTT_QOS})`);
  });
});

mqttClient.on('reconnect', () => {
  console.log('[realtime] MQTT reconnecting...');
});

mqttClient.on('close', () => {
  mqttConnected = false;
  console.warn('[realtime] MQTT disconnected');
});

mqttClient.on('message', async (topic, message) => {
  const payload = parsePayload(message);
  if (!payload) {
    console.warn('[realtime] dropped invalid payload');
    return;
  }

  try {
    const result = await ingestScan({ topic, ...payload });

    if (result.duplicate || result.ignored) {
      return;
    }

    sendEvent('child.moved', result.movementEvent);

    for (const snapshot of result.snapshots) {
      sendEvent('room.occupancy.updated', snapshot);
    }
  } catch (error) {
    console.error('[realtime] ingest failed', error);
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[realtime] listening on :${PORT}`);
});
