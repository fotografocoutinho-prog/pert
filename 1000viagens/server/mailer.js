/**
 * Envio de e-mail por SMTP — opcional e sem dependências.
 *
 * Configure por variáveis de ambiente:
 *   SMTP_HOST, SMTP_PORT (465 = TLS direto, 587 = STARTTLS),
 *   SMTP_USER, SMTP_PASS, SMTP_FROM
 *
 * Se não estiver configurado, o site funciona na mesma: os avisos de novos
 * pedidos ficam apenas no backoffice (e no webhook, se estiver definido).
 */
import net from 'node:net';
import tls from 'node:tls';

export function smtpConfigured() {
  return Boolean(process.env.SMTP_HOST && process.env.SMTP_USER && process.env.SMTP_PASS);
}

function connect({ host, port, secure }) {
  return new Promise((resolve, reject) => {
    const socket = secure
      ? tls.connect({ host, port, servername: host }, () => resolve(socket))
      : net.connect({ host, port }, () => resolve(socket));
    socket.setTimeout(15_000);
    socket.once('error', reject);
    socket.once('timeout', () => {
      socket.destroy();
      reject(new Error('Tempo esgotado a falar com o servidor SMTP.'));
    });
  });
}

/** Lê uma resposta SMTP completa (várias linhas terminam em "250 …"). */
function readReply(socket) {
  return new Promise((resolve, reject) => {
    let buffer = '';
    const onData = (chunk) => {
      buffer += chunk.toString('utf8');
      const lines = buffer.split(/\r?\n/).filter(Boolean);
      const last = lines[lines.length - 1];
      if (last && /^\d{3} /.test(last)) {
        cleanup();
        const code = Number(last.slice(0, 3));
        (code >= 400 ? reject : resolve)(
          code >= 400 ? new Error(`SMTP ${code}: ${last.slice(4)}`) : { code, text: buffer },
        );
      }
    };
    const onError = (err) => { cleanup(); reject(err); };
    const cleanup = () => {
      socket.off('data', onData);
      socket.off('error', onError);
    };
    socket.on('data', onData);
    socket.once('error', onError);
  });
}

async function command(socket, line) {
  socket.write(`${line}\r\n`);
  return readReply(socket);
}

/** Codifica o assunto para permitir acentos (RFC 2047). */
function encodeHeader(text) {
  // eslint-disable-next-line no-control-regex
  if (/^[\x00-\x7F]*$/.test(text)) return text;
  return `=?UTF-8?B?${Buffer.from(text, 'utf8').toString('base64')}?=`;
}

/**
 * Envia um e-mail simples em texto.
 * Nunca lança para fora: devolve {ok:false, error} em caso de falha.
 */
export async function sendMail({ to, subject, text }) {
  if (!smtpConfigured()) return { ok: false, error: 'SMTP não configurado.' };

  const host = process.env.SMTP_HOST;
  const port = Number(process.env.SMTP_PORT || 587);
  const user = process.env.SMTP_USER;
  const pass = process.env.SMTP_PASS;
  const from = process.env.SMTP_FROM || user;
  const secure = port === 465;

  let socket;
  try {
    socket = await connect({ host, port, secure });
    await readReply(socket);
    await command(socket, `EHLO 1000viagens`);

    if (!secure) {
      await command(socket, 'STARTTLS');
      socket = tls.connect({ socket, servername: host });
      await new Promise((resolve, reject) => {
        socket.once('secureConnect', resolve);
        socket.once('error', reject);
      });
      await command(socket, `EHLO 1000viagens`);
    }

    await command(socket, 'AUTH LOGIN');
    await command(socket, Buffer.from(user, 'utf8').toString('base64'));
    await command(socket, Buffer.from(pass, 'utf8').toString('base64'));
    await command(socket, `MAIL FROM:<${from}>`);
    await command(socket, `RCPT TO:<${to}>`);
    await command(socket, 'DATA');

    const body = [
      `From: ${encodeHeader('1000viagens')} <${from}>`,
      `To: <${to}>`,
      `Subject: ${encodeHeader(subject)}`,
      'MIME-Version: 1.0',
      'Content-Type: text/plain; charset=utf-8',
      'Content-Transfer-Encoding: base64',
      '',
      Buffer.from(text, 'utf8').toString('base64').replace(/(.{76})/g, '$1\r\n'),
      '.',
    ].join('\r\n');

    socket.write(`${body}\r\n`);
    await readReply(socket);
    await command(socket, 'QUIT').catch(() => {});
    socket.end();
    return { ok: true };
  } catch (err) {
    socket?.destroy();
    console.warn('[smtp] falhou o envio:', err.message);
    return { ok: false, error: err.message };
  }
}
