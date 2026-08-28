# Vendored: Claude Cookbooks

Esta pasta é uma cópia integral do repositório oficial
[anthropics/claude-cookbooks](https://github.com/anthropics/claude-cookbooks),
incluída no `pert` para ficar disponível offline.

| | |
|---|---|
| Origem | https://github.com/anthropics/claude-cookbooks |
| Commit | `35f2eec7e44897c537e44441b7dff2f0ecbfb804` |
| Data do commit | 2026-08-14 |
| Licença | MIT (ver `LICENSE`) |
| Ficheiros | 670 (árvore versionada do upstream, sem `.git`) |

## Setup

Requer [uv](https://docs.astral.sh/uv/) e Python 3.11–3.12:

```bash
cd claude-cookbooks
cp .env.example .env      # depois preenche ANTHROPIC_API_KEY
uv sync
uv run jupyter notebook
```

O `.venv/` criado pelo `uv sync` é ignorado pelo git (ver `.gitignore` desta pasta),
tal como o `.env` com a tua chave de API.

## Atualizar

Esta cópia não é um submódulo — não recebe atualizações automáticas. Para
atualizar, substitui a árvore pelo upstream mais recente:

```bash
git clone --depth 1 https://github.com/anthropics/claude-cookbooks.git /tmp/cb
rm -rf claude-cookbooks && mkdir claude-cookbooks
git -C /tmp/cb archive HEAD | tar -x -C claude-cookbooks
```

Depois atualiza o commit e a data indicados acima.
