/**
 * Armazenamento em ficheiros JSON, com escrita atómica.
 *
 * Chega perfeitamente para o volume de uma agência (milhares de pedidos) e
 * evita depender de uma base de dados externa. Se um dia o volume crescer,
 * basta substituir este módulo por outro com a mesma interface.
 */
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';

export class JsonStore {
  /**
   * @param {string} file  caminho do ficheiro
   * @param {object} initial  conteúdo inicial se o ficheiro não existir
   */
  constructor(file, initial) {
    this.file = file;
    this.initial = initial;
    this.data = null;
    this.queue = Promise.resolve();
  }

  async load() {
    if (this.data) return this.data;
    await fsp.mkdir(path.dirname(this.file), { recursive: true });
    try {
      const raw = await fsp.readFile(this.file, 'utf8');
      this.data = JSON.parse(raw);
    } catch (err) {
      if (err.code !== 'ENOENT' && !(err instanceof SyntaxError)) throw err;
      if (err instanceof SyntaxError) {
        // Nunca destruir dados: guarda o ficheiro corrompido para inspeção.
        const backup = `${this.file}.corrompido-${Date.now()}`;
        await fsp.rename(this.file, backup).catch(() => {});
        console.warn(`[store] ${this.file} ilegível — copiado para ${backup}`);
      }
      this.data = structuredClone(this.initial);
      await this.flush();
    }
    return this.data;
  }

  /** Escreve para ficheiro temporário e renomeia (atómico no mesmo volume). */
  async flush() {
    const tmp = `${this.file}.${process.pid}.tmp`;
    const payload = JSON.stringify(this.data, null, 2);
    await fsp.writeFile(tmp, payload, 'utf8');
    await fsp.rename(tmp, this.file);
  }

  /**
   * Muta os dados em série (evita perder escritas concorrentes) e grava.
   * @param {(data: object) => any} mutator
   */
  async update(mutator) {
    this.queue = this.queue.then(async () => {
      await this.load();
      const result = await mutator(this.data);
      await this.flush();
      return result;
    });
    return this.queue;
  }

  /** Leitura direta (não muta). */
  async read() {
    return this.load();
  }
}

/** Garante que uma pasta existe (versão síncrona, usada no arranque). */
export function ensureDirSync(dir) {
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}
