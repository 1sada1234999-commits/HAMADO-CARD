const express = require('express');
const cors = require('cors');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;
const DB_FILE = path.join(__dirname, 'data.json');

app.use(cors());
app.use(express.json({ limit: '10mb' }));

function nowIso() {
  return new Date().toISOString();
}

function readDb() {
  if (!fs.existsSync(DB_FILE)) {
    const seed = {
      users: [],
      sessions: [],
      products: [
        { id: 1, name: 'Free Fire 600', icon: '💎', price: 2.5, category: 'games' },
        { id: 2, name: 'PUBG 600 UC', icon: '🎮', price: 8.99, category: 'games' },
        { id: 3, name: 'Netflix Monthly', icon: '🎬', price: 6.5, category: 'apps' }
      ],
      orders: [],
      deposits: [],
      transactions: [],
      logs: [],
      settings: {
        usd_rate: 14000,
        shamcash_number: 'd44c9e5f8923c6585be02cf1a333b37c',
        syriatel_number: '',
        mtn_number: '',
        telegram_bot_token: '',
        telegram_chat_id: ''
      },
      admin: {
        username: 'admin',
        password: 'Hamado@2025!'
      }
    };
    fs.writeFileSync(DB_FILE, JSON.stringify(seed, null, 2));
  }
  return JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
}

function writeDb(db) {
  fs.writeFileSync(DB_FILE, JSON.stringify(db, null, 2));
}

function createToken(prefix = 'hc') {
  return `${prefix}_${crypto.randomBytes(16).toString('hex')}`;
}

function getIp(req) {
  return req.headers['x-forwarded-for']?.toString().split(',')[0].trim() || req.socket.remoteAddress || '0.0.0.0';
}

function addLog(db, req, userId, action, details = {}) {
  db.logs.unshift({
    id: crypto.randomUUID(),
    user_id: userId || null,
    ip: getIp(req),
    action,
    details,
    time: nowIso()
  });
  db.logs = db.logs.slice(0, 5000);
}

function authUser(req, res, next) {
  const token = req.headers['x-hc-token'];
  if (!token) return res.status(401).json({ message: 'Missing token' });
  const db = readDb();
  const session = db.sessions.find((s) => s.token === token && s.type === 'user');
  if (!session) return res.status(401).json({ message: 'Invalid token' });
  const user = db.users.find((u) => u.id === session.user_id);
  if (!user) return res.status(401).json({ message: 'User not found' });
  req.db = db;
  req.user = user;
  req.session = session;
  next();
}

function authAdmin(req, res, next) {
  const token = req.headers['x-admin-token'] || req.headers['x-hc-token'];
  if (!token) return res.status(401).json({ message: 'Missing admin token' });
  const db = readDb();
  const session = db.sessions.find((s) => s.token === token && s.type === 'admin');
  if (!session) return res.status(401).json({ message: 'Invalid admin token' });
  req.db = db;
  req.session = session;
  next();
}

function userPublic(user) {
  return {
    id: user.id,
    name: user.name,
    email: user.email,
    avatar: user.avatar || '👤',
    balance: user.balance || 0,
    kyc_status: user.kyc_status || 'none',
    total_spent: user.total_spent || 0,
    role: user.role || 'user',
    is_admin: Boolean(user.is_admin),
    last_login_at: user.last_login_at || null,
    last_login_ip: user.last_login_ip || null
  };
}

app.get('/api/health', (req, res) => {
  res.json({ success: true, time: nowIso() });
});

app.get('/api/settings/public', (req, res) => {
  const db = readDb();
  res.json({ success: true, ...db.settings });
});

app.get('/api/products', (req, res) => {
  const db = readDb();
  res.json({ success: true, products: db.products });
});

app.post('/api/auth/register', (req, res) => {
  const { name, email, password } = req.body || {};
  if (!name || !email || !password) return res.status(400).json({ message: 'Missing fields' });
  const db = readDb();
  if (db.users.some((u) => u.email.toLowerCase() === email.toLowerCase())) {
    return res.status(400).json({ message: 'Email already exists' });
  }
  const user = {
    id: crypto.randomUUID(),
    name,
    email,
    password,
    balance: 0,
    kyc_status: 'none',
    total_spent: 0,
    created_at: nowIso(),
    role: 'user',
    is_admin: false,
    last_login_at: null,
    last_login_ip: null
  };
  db.users.push(user);
  addLog(db, req, user.id, 'register', { email: user.email });
  writeDb(db);
  res.json({ success: true, message: 'Registered successfully' });
});

app.post('/api/auth/login', (req, res) => {
  const { email, password } = req.body || {};
  const db = readDb();
  const user = db.users.find((u) => u.email.toLowerCase() === (email || '').toLowerCase() && u.password === password);
  if (!user) return res.status(401).json({ message: 'Invalid credentials' });

  const token = createToken('user');
  const loginTime = nowIso();
  user.last_login_at = loginTime;
  user.last_login_ip = getIp(req);
  db.sessions.push({ token, user_id: user.id, type: 'user', created_at: loginTime, ip: user.last_login_ip });
  addLog(db, req, user.id, 'login', { email: user.email });
  writeDb(db);

  res.json({ success: true, token, user: userPublic(user) });
});

app.post('/api/auth/google', (req, res) => {
  const { email, name, google_id, avatar_url } = req.body || {};
  if (!email || !google_id) return res.status(400).json({ message: 'Missing Google data' });
  const db = readDb();
  let user = db.users.find((u) => u.email.toLowerCase() === email.toLowerCase());
  if (!user) {
    user = {
      id: crypto.randomUUID(),
      name: name || email,
      email,
      password: null,
      google_id,
      avatar: avatar_url || '😊',
      balance: 0,
      kyc_status: 'none',
      total_spent: 0,
      created_at: nowIso(),
      role: 'user',
      is_admin: false,
      last_login_at: null,
      last_login_ip: null
    };
    db.users.push(user);
  }
  const token = createToken('user');
  user.last_login_at = nowIso();
  user.last_login_ip = getIp(req);
  db.sessions.push({ token, user_id: user.id, type: 'user', created_at: nowIso(), ip: user.last_login_ip });
  addLog(db, req, user.id, 'google_login', { email: user.email });
  writeDb(db);
  res.json({ success: true, token, user: userPublic(user) });
});

app.get('/api/auth/me', authUser, (req, res) => {
  res.json({ success: true, user: userPublic(req.user) });
});

app.post('/api/auth/logout', authUser, (req, res) => {
  req.db.sessions = req.db.sessions.filter((s) => s.token !== req.session.token);
  addLog(req.db, req, req.user.id, 'logout', {});
  writeDb(req.db);
  res.json({ success: true });
});

app.get('/api/wallet', authUser, (req, res) => {
  res.json({ success: true, balance: req.user.balance || 0 });
});

app.get('/api/wallet/transactions', authUser, (req, res) => {
  const tx = req.db.transactions.filter((t) => t.user_id === req.user.id);
  res.json({ success: true, transactions: tx });
});

app.post('/api/wallet/deposit', authUser, (req, res) => {
  const { amount, method, ref } = req.body || {};
  if (!amount || !method) return res.status(400).json({ message: 'Missing deposit data' });

  const deposit = {
    id: crypto.randomUUID(),
    user_id: req.user.id,
    user_name: req.user.name,
    amount: Number(amount),
    method,
    ref: ref || '',
    status: 'pending',
    ip: getIp(req),
    type: 'deposit',
    payment_type: method,
    created_at: nowIso()
  };
  req.db.deposits.unshift(deposit);
  addLog(req.db, req, req.user.id, 'deposit_request', deposit);
  writeDb(req.db);
  res.json({ success: true, deposit });
});

app.get('/api/orders', authUser, (req, res) => {
  const orders = req.db.orders.filter((o) => o.user_id === req.user.id);
  res.json({ success: true, orders });
});

app.post('/api/orders/create', authUser, (req, res) => {
  const { product_id, player_id, quantity } = req.body || {};
  const product = req.db.products.find((p) => String(p.id) === String(product_id));
  if (!product) return res.status(404).json({ message: 'Product not found' });

  const qty = Number(quantity) || 1;
  const total = Number(product.price) * qty;
  if ((req.user.balance || 0) < total) return res.status(400).json({ message: 'Insufficient balance' });

  const before = req.user.balance;
  req.user.balance = Number((req.user.balance - total).toFixed(3));
  req.user.total_spent = Number((req.user.total_spent || 0) + total);

  const order = {
    id: crypto.randomUUID(),
    order_number: `ORD-${Date.now()}`,
    user_id: req.user.id,
    user_name: req.user.name,
    user_email: req.user.email,
    product_id: product.id,
    product_name: product.name,
    product_icon: product.icon,
    player_id,
    quantity: qty,
    total_price: total,
    status: 'pending',
    ip: getIp(req),
    transaction_type: 'purchase',
    payment_type: 'wallet',
    created_at: nowIso()
  };

  const tx = {
    id: crypto.randomUUID(),
    user_id: req.user.id,
    type: 'purchase',
    amount: -total,
    description: `شراء: ${product.name}`,
    payment_type: 'wallet',
    transaction_type: 'purchase',
    ip: getIp(req),
    balance_before: before,
    balance_after: req.user.balance,
    created_at: nowIso()
  };

  req.db.orders.unshift(order);
  req.db.transactions.unshift(tx);
  addLog(req.db, req, req.user.id, 'order_create', order);
  writeDb(req.db);

  res.json({ success: true, order_number: order.order_number, balance: req.user.balance, order });
});

app.post('/api/kyc/submit', authUser, (req, res) => {
  req.user.kyc_status = 'pending';
  req.user.kyc_data = {
    ...req.body,
    submitted_at: nowIso(),
    ip: getIp(req)
  };
  addLog(req.db, req, req.user.id, 'kyc_submit', {});
  writeDb(req.db);
  res.json({ success: true, kyc_status: 'pending' });
});

app.post('/api/request-product', authUser, (req, res) => {
  addLog(req.db, req, req.user.id, 'request_product', req.body || {});
  writeDb(req.db);
  res.json({ success: true });
});

app.post('/api/auth/admin-login', (req, res) => {
  const { username, password } = req.body || {};
  const db = readDb();
  if (username !== db.admin.username || password !== db.admin.password) {
    return res.status(401).json({ success: false, message: 'Invalid admin credentials' });
  }
  const token = createToken('admin');
  db.sessions.push({ token, type: 'admin', user_id: 'admin', created_at: nowIso(), ip: getIp(req) });
  addLog(db, req, null, 'admin_login', { username });
  writeDb(db);
  res.json({ success: true, is_admin: true, token });
});

app.get('/api/admin/stats', authAdmin, (req, res) => {
  const pendingOrders = req.db.orders.filter((o) => o.status === 'pending').length;
  const pendingDeposits = req.db.deposits.filter((d) => d.status === 'pending').length;
  res.json({
    success: true,
    users: req.db.users.length,
    orders: req.db.orders.length,
    pending_orders: pendingOrders,
    pending_deposits: pendingDeposits
  });
});

app.get('/api/admin/orders', authAdmin, (req, res) => res.json({ success: true, orders: req.db.orders }));

app.post('/api/admin/orders/:id/complete', authAdmin, (req, res) => {
  const order = req.db.orders.find((o) => o.id === req.params.id || o.order_number === req.params.id);
  if (!order) return res.status(404).json({ message: 'Order not found' });
  order.status = 'completed';
  order.admin_note = req.body?.note || '';
  order.completed_at = nowIso();
  addLog(req.db, req, order.user_id, 'admin_order_complete', { id: order.id });
  writeDb(req.db);
  res.json({ success: true, order });
});

app.post('/api/admin/orders/:id/reject', authAdmin, (req, res) => {
  const order = req.db.orders.find((o) => o.id === req.params.id || o.order_number === req.params.id);
  if (!order) return res.status(404).json({ message: 'Order not found' });
  if (order.status !== 'rejected') {
    const user = req.db.users.find((u) => u.id === order.user_id);
    if (user) user.balance = Number((user.balance + order.total_price).toFixed(3));
  }
  order.status = 'rejected';
  order.admin_note = req.body?.note || '';
  addLog(req.db, req, order.user_id, 'admin_order_reject', { id: order.id });
  writeDb(req.db);
  res.json({ success: true, order });
});

app.get('/api/admin/deposits', authAdmin, (req, res) => res.json({ success: true, deposits: req.db.deposits }));

app.post('/api/admin/deposits/:id/approve', authAdmin, (req, res) => {
  const dep = req.db.deposits.find((d) => d.id === req.params.id);
  if (!dep) return res.status(404).json({ message: 'Deposit not found' });
  if (dep.status !== 'approved') {
    const user = req.db.users.find((u) => u.id === dep.user_id);
    if (user) {
      const before = user.balance || 0;
      user.balance = Number((before + Number(dep.amount)).toFixed(3));
      req.db.transactions.unshift({
        id: crypto.randomUUID(),
        user_id: user.id,
        type: 'deposit',
        amount: Number(dep.amount),
        description: `إيداع مقبول (${dep.method})`,
        payment_type: dep.method,
        transaction_type: 'deposit',
        ip: dep.ip,
        balance_before: before,
        balance_after: user.balance,
        created_at: nowIso()
      });
    }
  }
  dep.status = 'approved';
  dep.approved_at = nowIso();
  addLog(req.db, req, dep.user_id, 'admin_deposit_approve', { id: dep.id });
  writeDb(req.db);
  res.json({ success: true, deposit: dep });
});

app.post('/api/admin/deposits/:id/reject', authAdmin, (req, res) => {
  const dep = req.db.deposits.find((d) => d.id === req.params.id);
  if (!dep) return res.status(404).json({ message: 'Deposit not found' });
  dep.status = 'rejected';
  dep.rejected_at = nowIso();
  addLog(req.db, req, dep.user_id, 'admin_deposit_reject', { id: dep.id });
  writeDb(req.db);
  res.json({ success: true, deposit: dep });
});

app.get('/api/admin/users', authAdmin, (req, res) => {
  const users = req.db.users.map((u) => ({ ...userPublic(u), created_at: u.created_at }));
  res.json({ success: true, users });
});

app.post('/api/admin/users/:id/balance', authAdmin, (req, res) => {
  const user = req.db.users.find((u) => u.id === req.params.id);
  if (!user) return res.status(404).json({ message: 'User not found' });
  const newBalance = Number(req.body?.balance);
  if (Number.isNaN(newBalance)) return res.status(400).json({ message: 'Invalid balance' });
  user.balance = newBalance;
  addLog(req.db, req, user.id, 'admin_set_balance', { balance: newBalance });
  writeDb(req.db);
  res.json({ success: true, user: userPublic(user) });
});

app.get('/api/admin/kyc', authAdmin, (req, res) => {
  const kyc = req.db.users.filter((u) => (u.kyc_status || 'none') !== 'none');
  res.json({ success: true, users: kyc.map((u) => ({ id: u.id, name: u.name, email: u.email, kyc_status: u.kyc_status, kyc_data: u.kyc_data || null })) });
});

app.post('/api/admin/kyc/:uid/approve', authAdmin, (req, res) => {
  const user = req.db.users.find((u) => u.id === req.params.uid);
  if (!user) return res.status(404).json({ message: 'User not found' });
  user.kyc_status = 'approved';
  addLog(req.db, req, user.id, 'admin_kyc_approve', {});
  writeDb(req.db);
  res.json({ success: true });
});

app.post('/api/admin/kyc/:uid/reject', authAdmin, (req, res) => {
  const user = req.db.users.find((u) => u.id === req.params.uid);
  if (!user) return res.status(404).json({ message: 'User not found' });
  user.kyc_status = 'rejected';
  addLog(req.db, req, user.id, 'admin_kyc_reject', {});
  writeDb(req.db);
  res.json({ success: true });
});

app.post('/api/admin/settings', authAdmin, (req, res) => {
  req.db.settings = { ...req.db.settings, ...(req.body || {}) };
  addLog(req.db, req, null, 'admin_settings_update', req.body || {});
  writeDb(req.db);
  res.json({ success: true, settings: req.db.settings });
});

app.get('/api/admin/activity', authAdmin, (req, res) => {
  res.json({ success: true, logs: req.db.logs.slice(0, 200) });
});

app.get('/api/admin/sessions', authAdmin, (req, res) => {
  const userSessions = req.db.sessions.filter((s) => s.type === 'user');
  res.json({ success: true, sessions: userSessions });
});

app.get('/api/admin/transactions', authAdmin, (req, res) => {
  res.json({ success: true, transactions: req.db.transactions });
});

app.listen(PORT, () => {
  console.log(`Hamado backend running on http://localhost:${PORT}`);
});
