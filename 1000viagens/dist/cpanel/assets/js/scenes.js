/* ============================================================================
   1000viagens — ilustrações e ícones desenhados em SVG.
   Nada é carregado de fora: o site funciona sem CDN, sem tipos de letra
   externos e sem fotografias de stock (mas aceita fotos enviadas no backoffice).
   ========================================================================== */

(function (global) {
  'use strict';

  /** Cada cena recebe um sufixo único para os ids dos gradientes não colidirem. */
  const SCENES = {
    tropical: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Praia tropical com bungalows sobre a água">
        <defs>
          <linearGradient id="sky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#7ED0E8"/><stop offset="60%" stop-color="#C8ECF3"/><stop offset="100%" stop-color="#FCEBCF"/>
          </linearGradient>
          <linearGradient id="water${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#37B7C4"/><stop offset="55%" stop-color="#12889E"/><stop offset="100%" stop-color="#0A5C77"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#sky${u})"/>
        <circle cx="312" cy="70" r="30" fill="#FFE2AC"/>
        <circle cx="312" cy="70" r="46" fill="#FFE2AC" opacity=".3"/>
        <path d="M0 150h400v150H0z" fill="url(#water${u})"/>
        <g fill="#FFFFFF" opacity=".45">
          <rect x="40" y="196" width="90" height="3" rx="1.5"/><rect x="180" y="222" width="120" height="3" rx="1.5"/>
          <rect x="90" y="250" width="70" height="3" rx="1.5"/><rect x="240" y="268" width="110" height="3" rx="1.5"/>
        </g>
        <path d="M0 150c60-8 90 6 150 2s110-14 250-4v6H0z" fill="#9FE5E0" opacity=".7"/>
        <g fill="#6B4630">
          <rect x="238" y="150" width="5" height="34"/><rect x="288" y="150" width="5" height="34"/>
          <rect x="330" y="150" width="5" height="34"/>
        </g>
        <g>
          <path d="M226 128h72l-36-26z" fill="#B8703C"/><rect x="232" y="128" width="60" height="24" fill="#FBF3E4"/>
          <path d="M300 136h64l-32-22z" fill="#9C5C31"/><rect x="306" y="136" width="52" height="18" fill="#F2E7D4"/>
        </g>
        <g fill="#0C5B4A">
          <path d="M56 300c2-52 6-78 14-104l14 3c-8 26-12 52-14 101z"/>
          <path d="M74 196c-30-24-62-26-74-14 14-24 56-24 76-4z"/>
          <path d="M76 194c-14-34-42-52-72-58 42-6 74 20 84 52z"/>
          <path d="M82 192c14-32 46-50 84-52-36 14-62 34-72 60z"/>
          <path d="M84 198c34-16 74-12 100 6-34-12-68-12-96 2z"/>
        </g>
        <g fill="#FBF3E4" opacity=".9"><ellipse cx="200" cy="150" rx="200" ry="4"/></g>
      </svg>`,

    mediterranean: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Casas brancas com cúpulas azuis sobre o mar Egeu">
        <defs>
          <linearGradient id="sky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#5BA9D8"/><stop offset="70%" stop-color="#BEE1F2"/><stop offset="100%" stop-color="#FBE4C8"/>
          </linearGradient>
          <linearGradient id="sea${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#1D6FA8"/><stop offset="100%" stop-color="#0B3F66"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#sky${u})"/>
        <circle cx="70" cy="62" r="26" fill="#FFF0D2"/>
        <rect y="176" width="400" height="124" fill="#12639B"/>
        <path d="M0 176h400v124H0z" fill="#0C4E7D" opacity=".45"/>
        <g fill="#FFFFFF" opacity=".35">
          <rect x="30" y="212" width="110" height="3" rx="1.5"/><rect x="200" y="240" width="140" height="3" rx="1.5"/>
          <rect x="80" y="268" width="90" height="3" rx="1.5"/>
        </g>
        <path d="M0 176c40-30 80-14 120-40s70-20 110-42 100-16 170-24v106z" fill="#F4EDE2"/>
        <g fill="#FDFBF7" stroke="#E3D9C8" stroke-width="1.5">
          <rect x="42" y="120" width="64" height="56" rx="3"/>
          <rect x="120" y="96" width="52" height="80" rx="3"/>
          <rect x="188" y="126" width="70" height="50" rx="3"/>
          <rect x="272" y="102" width="58" height="74" rx="3"/>
          <rect x="336" y="132" width="52" height="44" rx="3"/>
        </g>
        <g fill="#1B6FA8">
          <path d="M120 96a26 20 0 0 1 52 0z"/><path d="M272 102a29 22 0 0 1 58 0z"/>
          <rect x="52" y="136" width="12" height="14" rx="2"/><rect x="82" y="136" width="12" height="14" rx="2"/>
          <rect x="204" y="142" width="12" height="14" rx="2"/><rect x="232" y="142" width="12" height="14" rx="2"/>
          <rect x="350" y="146" width="12" height="14" rx="2"/>
        </g>
        <g fill="#E8927A">
          <path d="M42 120h64l-32-16z"/><path d="M188 126h70l-35-16z"/><path d="M336 132h52l-26-14z"/>
        </g>
      </svg>`,

    nordic: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Aurora boreal sobre floresta nevada">
        <defs>
          <linearGradient id="night${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#071A33"/><stop offset="55%" stop-color="#123255"/><stop offset="100%" stop-color="#2E5878"/>
          </linearGradient>
          <linearGradient id="aur1${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#7BF7C4" stop-opacity="0"/><stop offset="45%" stop-color="#5BE8B8" stop-opacity=".75"/><stop offset="100%" stop-color="#7B7BE8" stop-opacity="0"/>
          </linearGradient>
          <linearGradient id="aur2${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#B48CF2" stop-opacity="0"/><stop offset="50%" stop-color="#8FD8F5" stop-opacity=".6"/><stop offset="100%" stop-color="#8FD8F5" stop-opacity="0"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#night${u})"/>
        <g fill="#FFFFFF">
          <circle cx="46" cy="34" r="1.6" opacity=".9"/><circle cx="122" cy="20" r="1.1" opacity=".7"/><circle cx="210" cy="42" r="1.5" opacity=".8"/>
          <circle cx="298" cy="26" r="1.2" opacity=".7"/><circle cx="356" cy="58" r="1.6" opacity=".9"/><circle cx="164" cy="72" r="1" opacity=".6"/>
        </g>
        <path d="M-20 128c60-70 130-40 190-86 46-36 130-30 250-18v-40H-20z" fill="url(#aur1${u})" opacity=".9"/>
        <path d="M-20 158c70-52 140-24 210-64 50-28 140-24 230-6v-44c-120-10-200 0-250 30-60 36-130 10-190 62z" fill="url(#aur2${u})"/>
        <g fill="#0B2038">
          <path d="M0 220h400v80H0z"/>
        </g>
        <path d="M0 224c56-26 104 8 152-10s96-24 148-2 76 10 100 4v84H0z" fill="#EAF3FA"/>
        <g fill="#0D2B3E">
          <path d="M60 226l-16 34h32zM60 200l-13 28h26z"/>
          <path d="M116 236l-13 28h26zM116 214l-10 22h20z"/>
          <path d="M300 230l-15 32h30zM300 206l-12 26h24z"/>
          <path d="M344 240l-11 24h22zM344 222l-9 20h18z"/>
        </g>
        <g fill="#12384F"><rect x="56" y="256" width="8" height="12"/><rect x="112" y="260" width="7" height="10"/><rect x="296" y="258" width="8" height="12"/><rect x="341" y="262" width="6" height="10"/></g>
      </svg>`,

    desert: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Dunas do deserto ao pôr do sol com palmeiras">
        <defs>
          <linearGradient id="dsky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#F2A65A"/><stop offset="45%" stop-color="#F6C48A"/><stop offset="100%" stop-color="#FBE3BE"/>
          </linearGradient>
          <linearGradient id="dune1${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#E5A96A"/><stop offset="100%" stop-color="#D08C4F"/>
          </linearGradient>
          <linearGradient id="dune2${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#C67C42"/><stop offset="100%" stop-color="#A65F31"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#dsky${u})"/>
        <circle cx="128" cy="104" r="38" fill="#FFF0CF"/>
        <circle cx="128" cy="104" r="60" fill="#FFF0CF" opacity=".28"/>
        <g fill="#B26A38" opacity=".9">
          <rect x="252" y="112" width="10" height="76"/>
          <path d="M257 96a12 12 0 0 1 12 16h-24a12 12 0 0 1 12-16z"/>
          <rect x="238" y="182" width="38" height="30" rx="2"/>
          <rect x="284" y="158" width="56" height="54" rx="2"/>
          <path d="M284 158h56l-28-18z"/>
        </g>
        <path d="M0 196c70-34 118 12 178-8s108-22 222 4v104H0z" fill="url(#dune1${u})"/>
        <path d="M0 240c80-28 130 22 196 4s122-14 204 10v46H0z" fill="url(#dune2${u})"/>
        <g fill="#7B4423">
          <path d="M64 300c0-40 3-58 8-76l10 2c-5 18-8 36-8 74z"/>
          <path d="M76 224c-22-16-44-18-52-10 10-16 40-16 54-2z"/>
          <path d="M76 222c-10-24-30-38-52-42 30-4 54 14 60 38z"/>
          <path d="M82 220c10-22 34-36 60-38-26 10-44 24-52 42z"/>
          <path d="M84 226c24-12 52-8 70 4-24-8-48-8-68 2z"/>
        </g>
      </svg>`,

    cruise: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Navio de cruzeiro ao largo, ao entardecer">
        <defs>
          <linearGradient id="csky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#2E6E9E"/><stop offset="55%" stop-color="#8FC4DE"/><stop offset="100%" stop-color="#FAD9AE"/>
          </linearGradient>
          <linearGradient id="csea${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#1C6C95"/><stop offset="100%" stop-color="#083447"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#csky${u})"/>
        <circle cx="326" cy="128" r="26" fill="#FFE9C0"/>
        <rect y="186" width="400" height="114" fill="url(#csea${u})"/>
        <g fill="#FFD9A8" opacity=".5">
          <rect x="286" y="204" width="80" height="3" rx="1.5"/><rect x="266" y="228" width="120" height="3" rx="1.5"/>
          <rect x="300" y="252" width="70" height="3" rx="1.5"/>
        </g>
        <g fill="#0A3E56" opacity=".55"><path d="M0 186c40-6 60 4 110 0s70-10 120-4v6H0z"/></g>
        <g>
          <path d="M62 186h228l-22 30H84z" fill="#123C55"/>
          <rect x="78" y="160" width="196" height="26" rx="3" fill="#FDFBF7"/>
          <rect x="96" y="140" width="160" height="20" rx="3" fill="#F2EADF"/>
          <rect x="118" y="124" width="112" height="16" rx="3" fill="#FDFBF7"/>
          <g fill="#1B7FA8">
            <rect x="86" y="168" width="9" height="9" rx="1.5"/><rect x="104" y="168" width="9" height="9" rx="1.5"/>
            <rect x="122" y="168" width="9" height="9" rx="1.5"/><rect x="140" y="168" width="9" height="9" rx="1.5"/>
            <rect x="158" y="168" width="9" height="9" rx="1.5"/><rect x="176" y="168" width="9" height="9" rx="1.5"/>
            <rect x="194" y="168" width="9" height="9" rx="1.5"/><rect x="212" y="168" width="9" height="9" rx="1.5"/>
            <rect x="230" y="168" width="9" height="9" rx="1.5"/><rect x="248" y="168" width="9" height="9" rx="1.5"/>
            <rect x="106" y="146" width="9" height="9" rx="1.5"/><rect x="124" y="146" width="9" height="9" rx="1.5"/>
            <rect x="142" y="146" width="9" height="9" rx="1.5"/><rect x="160" y="146" width="9" height="9" rx="1.5"/>
            <rect x="178" y="146" width="9" height="9" rx="1.5"/><rect x="196" y="146" width="9" height="9" rx="1.5"/>
            <rect x="214" y="146" width="9" height="9" rx="1.5"/><rect x="232" y="146" width="9" height="9" rx="1.5"/>
          </g>
          <rect x="150" y="96" width="18" height="30" rx="4" fill="#E85D4A"/>
          <rect x="182" y="96" width="18" height="30" rx="4" fill="#E85D4A"/>
        </g>
        <g stroke="#0A3E56" stroke-width="2" fill="none" opacity=".45" stroke-linecap="round">
          <path d="M300 96q8-7 16 0q8-7 16 0"/><path d="M344 74q6-5 12 0q6-5 12 0"/>
        </g>
      </svg>`,

    city: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Horizonte de arranha-céus ao anoitecer">
        <defs>
          <linearGradient id="usky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#1B2C4C"/><stop offset="55%" stop-color="#5B5A86"/><stop offset="100%" stop-color="#E9906C"/>
          </linearGradient>
          <linearGradient id="tower${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#233A5C"/><stop offset="100%" stop-color="#101F36"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#usky${u})"/>
        <circle cx="316" cy="64" r="20" fill="#FFF3D6"/>
        <circle cx="308" cy="58" r="20" fill="#5B5A86" opacity=".85"/>
        <g fill="url(#tower${u})">
          <rect x="8" y="168" width="42" height="132"/>
          <rect x="58" y="196" width="34" height="104"/>
          <rect x="100" y="140" width="46" height="160"/>
          <rect x="154" y="180" width="30" height="120"/>
          <rect x="192" y="108" width="40" height="192"/>
          <rect x="240" y="156" width="36" height="144"/>
          <rect x="284" y="128" width="44" height="172"/>
          <rect x="336" y="180" width="34" height="120"/>
          <rect x="376" y="204" width="24" height="96"/>
          <path d="M206 108h12v-26h-12z"/><path d="M300 128h12v-20h-12z"/>
        </g>
        <g fill="#FFD98A" opacity=".85">
          <rect x="16" y="180" width="7" height="9"/><rect x="30" y="196" width="7" height="9"/><rect x="16" y="214" width="7" height="9"/>
          <rect x="66" y="208" width="6" height="8"/><rect x="78" y="226" width="6" height="8"/>
          <rect x="110" y="152" width="8" height="10"/><rect x="126" y="172" width="8" height="10"/><rect x="110" y="196" width="8" height="10"/><rect x="126" y="220" width="8" height="10"/>
          <rect x="162" y="196" width="6" height="8"/><rect x="170" y="220" width="6" height="8"/>
          <rect x="200" y="124" width="8" height="10"/><rect x="216" y="148" width="8" height="10"/><rect x="200" y="176" width="8" height="10"/><rect x="216" y="206" width="8" height="10"/>
          <rect x="248" y="172" width="7" height="9"/><rect x="262" y="196" width="7" height="9"/>
          <rect x="292" y="144" width="8" height="10"/><rect x="310" y="168" width="8" height="10"/><rect x="292" y="196" width="8" height="10"/>
          <rect x="344" y="196" width="6" height="8"/><rect x="356" y="220" width="6" height="8"/>
        </g>
        <g fill="#0C1729" opacity=".5"><rect y="286" width="400" height="14"/></g>
      </svg>`,

    mountain: (u) => `
      <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Montanhas com neve e lago">
        <defs>
          <linearGradient id="msky${u}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#8CC5DE"/><stop offset="70%" stop-color="#D9EAF2"/><stop offset="100%" stop-color="#F7E8D2"/>
          </linearGradient>
        </defs>
        <rect width="400" height="300" fill="url(#msky${u})"/>
        <circle cx="330" cy="60" r="24" fill="#FFF2D4"/>
        <path d="M0 210 120 70l90 108 62-70 128 102v90H0z" fill="#4C7C93"/>
        <path d="M120 70l44 68-30 16-28-24z" fill="#FDFBF7"/>
        <path d="M272 108l40 32-24 10-22-18z" fill="#FDFBF7"/>
        <path d="M0 220 96 128l88 92 60-52 156 82v50H0z" fill="#2F5C74"/>
        <rect y="238" width="400" height="62" fill="#1D4459"/>
        <g fill="#FFFFFF" opacity=".25"><rect x="60" y="256" width="120" height="3" rx="1.5"/><rect x="220" y="276" width="140" height="3" rx="1.5"/></g>
      </svg>`,
  };

  const FALLBACK = SCENES.tropical;

  /** Devolve o SVG de uma cena. */
  function scene(name, uid) {
    const factory = SCENES[name] || FALLBACK;
    return factory(String(uid).replace(/\W/g, ''));
  }

  /* ── Ícones de linha (24×24) ────────────────────────────────────────────── */
  const ICONS = {
    sun: '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6" stroke-linecap="round"/>',
    city: '<path d="M3 21h18M5 21V8l6-4v17M11 21V10l8-3v14" stroke-linejoin="round"/><path d="M8 12h.01M8 16h.01M15 12h.01M15 16h.01" stroke-linecap="round"/>',
    ship: '<path d="M3 18.5c1.6 0 1.6 1.5 3.2 1.5s1.6-1.5 3.2-1.5 1.6 1.5 3.2 1.5 1.6-1.5 3.2-1.5 1.6 1.5 3.2 1.5" stroke-linecap="round"/><path d="M4.5 15 6 9h12l1.5 6" stroke-linejoin="round"/><path d="M12 9V4.5M9 6.5h6" stroke-linecap="round"/>',
    mountain: '<path d="m3 19 6-11 4 6 2.5-3.5L21 19z" stroke-linejoin="round"/><circle cx="17" cy="6" r="1.8"/>',
    safari: '<path d="M4 18c1.5-5 4-7.5 8-7.5s6.5 2.5 8 7.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5" /><path d="M2.5 18h19" stroke-linecap="round"/>',
    heart: '<path d="M12 20s-7-4.4-7-9.3A4 4 0 0 1 12 8a4 4 0 0 1 7 2.7c0 4.9-7 9.3-7 9.3Z" stroke-linejoin="round"/>',
    family: '<circle cx="8" cy="7" r="2.6"/><circle cx="16.5" cy="8.5" r="2"/><path d="M3.5 20v-2.5A4 4 0 0 1 7.5 13h1a4 4 0 0 1 4 4.5V20M14 20v-2a3 3 0 0 1 3-3h.5a3 3 0 0 1 3 3v2" stroke-linecap="round" stroke-linejoin="round"/>',
    snow: '<path d="M12 2.5v19M4 7l16 10M20 7 4 17" stroke-linecap="round"/><path d="m9 4.5 3 2 3-2M9 19.5l3-2 3 2" stroke-linecap="round" stroke-linejoin="round"/>',
    route: '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.5 6h5a3.5 3.5 0 0 1 0 7h-3a3.5 3.5 0 0 0 0 7h5" stroke-linecap="round"/>',
    briefcase: '<rect x="3" y="7.5" width="18" height="12.5" rx="2.5"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5M3 13h18" stroke-linecap="round"/>',
    compass: '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5.2-5.2 2 2-5.2z" stroke-linejoin="round"/>',
    wallet: '<path d="M3 8.5A2.5 2.5 0 0 1 5.5 6H18a2 2 0 0 1 2 2v1" stroke-linecap="round"/><rect x="3" y="8.5" width="18" height="11" rx="2.5"/><circle cx="16.5" cy="14" r="1.3"/>',
    shield: '<path d="M12 21s7.5-3.7 7.5-9.4V5.6L12 3 4.5 5.6V11.6C4.5 17.3 12 21 12 21Z" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>',
    plane: '<path d="M10.5 19.5 12 22l1.5-2.5M2 13.5l20-7-3.5 8.5-7 1.5-3.5 4-1-5.5z" stroke-linejoin="round"/>',
  };

  /** Devolve um ícone de linha pronto a inserir. */
  function icon(name, size = 20) {
    const body = ICONS[name] || ICONS.plane;
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">${body}</svg>`;
  }

  global.MVArt = { scene, icon, SCENES, ICONS };
})(window);
