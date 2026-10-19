/* ============================================================
   assets/site/avcel-art.js — celebration drawings (row 17).

   Design: “Afrovanguard Celebrations” (3b doodle logos, 3a scenes,
   2b banner icons). Every drawing is plain SVG markup painted with
   --av-* tokens, so no colour lives here. Motion is declared with
   classes (.avcel-m--<entrance>, .avcel-i--<idle>) and played by
   assets/site/avcel.css; this file never animates anything itself.

   Loaded on demand by celebrations.js, only on a day with something
   to celebrate. Exposes window.AvCelArt.
   ============================================================ */
(function () {
  'use strict';
  if (window.AvCelArt) return;

  var V = function (n) { return 'var(--' + (n.indexOf('av-') === 0 ? n : 'av-cel-' + n) + ')'; };
  var n1 = function (x) { return (Math.round(x * 10) / 10).toString(); };
  var fl = function (c, extra) { return ' style="fill:' + V(c) + (extra || '') + '"'; };
  var sk = function (c, w, extra) { return ' style="fill:none;stroke:' + V(c) + ';stroke-width:' + w + (extra || '') + '"'; };
  var fs = function (f, s, w, extra) { return ' style="fill:' + V(f) + ';stroke:' + V(s) + ';stroke-width:' + w + (extra || '') + '"'; };

  var GOLD = 'av-quote-mark', GOLDD = 'av-link-hover', INK = 'av-ink', WHITE = 'av-white', PAPER = 'av-paper';

  function ring(cx, cy, n, r0, r1, cols, w) {
    var s = '';
    for (var i = 0; i < n; i++) {
      var a = i * Math.PI * 2 / n;
      s += '<line x1="' + n1(cx + Math.cos(a) * r0) + '" y1="' + n1(cy + Math.sin(a) * r0) + '" x2="' + n1(cx + Math.cos(a) * r1) + '" y2="' + n1(cy + Math.sin(a) * r1) + '"' + sk(cols[i % cols.length], w || 1.7, ';stroke-linecap:round') + '/>';
    }
    return s;
  }
  function petals(cx, cy, n, d, r, col, mid) {
    var s = '';
    for (var i = 0; i < n; i++) {
      var a = i * Math.PI * 2 / n - Math.PI / 2;
      s += '<circle cx="' + n1(cx + Math.cos(a) * d) + '" cy="' + n1(cy + Math.sin(a) * d) + '" r="' + r + '"' + fl(col) + '/>';
    }
    return s + '<circle cx="' + cx + '" cy="' + cy + '" r="' + n1(r * 0.68) + '"' + fl(mid) + '/>';
  }

  /* Short names and calendar line (used for kickers and labels). */
  var META = {
    newyear: ['New Year', '1 Jan'], womensday: ['Women’s Day', '8 Mar'], happiness: ['Day of Happiness', '20 Mar'],
    earthday: ['Earth Day', '22 Apr'], workersday: ['Workers’ Day', '1 May'], africaday: ['Africa Day', '25 May'],
    childrensday: ['Children’s Day', '27 May'], democracyday: ['Democracy Day', '12 Jun'], youthday: ['Youth Day', '12 Aug'],
    peaceday: ['Day of Peace', '21 Sep'], independence: ['Independence Day', '1 Oct'], girlchild: ['Day of the Girl', '11 Oct'],
    founding: ['Anniversary', ''], humanrights: ['Human Rights Day', '10 Dec'], christmas: ['Christmas', '25 Dec'],
    goodfriday: ['Good Friday', 'Movable'], easter: ['Easter', 'Movable'], eastermonday: ['Easter Monday', 'Movable'],
    eidfitr: ['Eid al-Fitr', '1 Shawwal'], eidadha: ['Eid al-Adha', '10 Dhul Hijjah'], birthday: ['Birthday', 'Any day']
  };

  /* ── 3b: the day’s symbol that replaces the “o” (24 × 24) ───────────── */
  var naija = '<circle cx="12" cy="13" r="8"' + fl(WHITE) + '/><path d="M6.4 7.3A8 8 0 0 0 6.4 18.7zM17.6 7.3a8 8 0 0 1 0 11.4z"' + fl('ng') + '/><path d="M8.3 6.2h2.4v13.6H8.3zM13.3 6.2h2.4v13.6h-2.4z"' + fl('ng') + '/><circle cx="12" cy="13" r="8"' + sk('ng', 1.4) + '/>';
  var star5 = 'M19.4 6.6l.6 1.3 1.4.2-1 1 .2 1.4-1.2-.7-1.3.7.3-1.4-1-1 1.4-.2z';
  var moon = 'M15.5 5.2a8 8 0 1 0 4.2 13.6A6.6 6.6 0 0 1 15.5 5.2z';
  function egg(body, line) {
    return '<path d="M12 4.6c4 0 7 5.4 7 9.4a7 7 0 0 1-14 0c0-4 3-9.4 7-9.4z"' + fl(body) + '/><path d="M5.3 12.6l2.2-1.6 2.2 1.6 2.3-1.6 2.2 1.6 2.3-1.6 2.2 1.6"' + sk(line, 1.3) + '/><path d="M5.4 16.4h13.2"' + sk('sage', 1.3) + '/>';
  }
  function gear(cx, cy, r, col, hole) {
    var s = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '"' + fl(col) + '/>';
    for (var i = 0; i < 8; i++) s += '<rect x="' + n1(cx - r * 0.22) + '" y="' + n1(cy - r * 1.32) + '" width="' + n1(r * 0.44) + '" height="' + n1(r * 0.5) + '" rx="1"' + fl(col) + ' transform="rotate(' + (i * 45) + ' ' + cx + ' ' + cy + ')"/>';
    return s + '<circle cx="' + cx + '" cy="' + cy + '" r="' + n1(r * 0.42) + '"' + fl(hole) + '/>';
  }
  function cog(hole) {
    var s = '<circle cx="12" cy="13" r="5.8"' + fl(GOLD) + '/>';
    for (var i = 0; i < 8; i++) s += '<rect x="10.6" y="3.6" width="2.8" height="3.4" rx=".6"' + fl(GOLD) + ' transform="rotate(' + (i * 45) + ' 12 13)"/>';
    return s + '<circle cx="12" cy="13" r="2.4"' + fl(hole) + '/>';
  }
  var GLYPH = {
    newyear: '<circle cx="12" cy="13" r="2.4"' + fl('amber') + '/>' + ring(12, 13, 12, 4.6, 9, ['amber', GOLD]),
    womensday: petals(12, 13, 5, 4.6, 3.8, 'plum', 'yolk'),
    happiness: '<circle cx="12" cy="13" r="8"' + fl('sun') + '/><circle cx="9.3" cy="11.4" r="1.1"' + fl('smile') + '/><circle cx="14.7" cy="11.4" r="1.1"' + fl('smile') + '/><path d="M8.4 14.6q3.6 3.6 7.2 0"' + sk('smile', 1.4, ';stroke-linecap:round') + '/>',
    earthday: '<circle cx="12" cy="13" r="8"' + fl('sky') + '/><path d="M7.5 9.5c2-1.6 4-.8 4.5.6.6 1.7-1.6 2.2-1.4 3.8.2 1.7-1.8 2.4-3.2 1.3-1.4-1.2-1.5-4.4.1-5.7zM14.2 14.2c1.3-.6 3.4-.2 3.6 1.2.2 1.5-1.6 2.8-3 2.4-1.3-.4-1.9-3-.6-3.6z"' + fl('leaf') + '/>',
    workersday: cog(PAPER),
    africaday: '<circle cx="12" cy="13" r="5.4"' + fl('amber') + '/>' + ring(12, 13, 8, 7.4, 9.8, ['forest', 'wine'], 1.8),
    childrensday: '<path d="M12 13V4.6A8.4 8.4 0 0 1 20.4 13z"' + fl('orange') + '/><path d="M12 13h8.4A8.4 8.4 0 0 1 12 21.4z"' + fl('sky') + '/><path d="M12 13v8.4A8.4 8.4 0 0 1 3.6 13z"' + fl('av-gold') + '/><path d="M12 13H3.6A8.4 8.4 0 0 1 12 4.6z"' + fl('rose') + '/><circle cx="12" cy="13" r="1.4"' + fl(WHITE) + '/>',
    democracyday: '<circle cx="12" cy="13" r="8"' + fl('ng') + '/><path d="M8 13.2l2.8 2.8 5.4-5.6"' + sk(WHITE, 2, ';stroke-linecap:round;stroke-linejoin:round') + '/>',
    founding: function (o) { var y = String(o.years || 9); return '<circle cx="12" cy="13" r="8.2"' + fl('amber') + '/><circle cx="12" cy="13" r="6.4"' + sk('wick', 0.8) + '/><text x="12" y="17.2" text-anchor="middle" font-size="' + (y.length > 1 ? 9 : 12) + '" font-weight="600" style="fill:' + V(INK) + ';font-family:var(--av-serif)">' + y + '</text>'; },
    youthday: '<circle cx="12" cy="13" r="8"' + fl(INK) + '/><path d="M12 7.4l1.5 3.4 3.6.4-2.7 2.4.8 3.6-3.2-1.9-3.2 1.9.8-3.6-2.7-2.4 3.6-.4z"' + fl('av-gold') + '/>',
    peaceday: '<circle cx="12" cy="13" r="8"' + fl('pale-sky') + '/><path d="M6.6 14.2c2.2-.2 3.6-1.6 4.6-3.6 1.2 1.8 3.2 2.6 5.8 2.2-1 2.6-3.8 4-6.6 3.6l-2 1.8.2-2.4c-1-.3-1.6-.9-2-1.6z"' + fs(WHITE, 'sky', 0.9, ';stroke-linejoin:round') + '/><circle cx="15.6" cy="12.8" r=".6"' + fl('sky') + '/>',
    independence: naija,
    girlchild: petals(12, 13, 5, 4.4, 3.6, 'pink', 'cream'),
    humanrights: '<circle cx="12" cy="13" r="8"' + fl('steel') + '/><path d="M12 7v10M8 17h8M7 9.6h10"' + sk(WHITE, 1.2, ';stroke-linecap:round') + '/><path d="M5.6 13.4l1.4-3.8 1.4 3.8zM15.6 13.4l1.4-3.8 1.4 3.8z"' + sk(WHITE, 0.9, ';stroke-linejoin:round') + '/><path d="M5.4 13.4h3.2M15.4 13.4h3.2"' + sk(WHITE, 1.2) + '/>',
    christmas: '<circle cx="12" cy="13" r="8"' + sk('pine', 3.4) + '/><circle cx="7.2" cy="7.6" r="1.6"' + fl('red') + '/><circle cx="9.6" cy="6.3" r="1.3"' + fl('red') + '/><path d="M12 21.4l-1.8 2.4h3.6z"' + fl('red') + '/>',
    goodfriday: '<circle cx="12" cy="13" r="8"' + fl('lilac-2') + '/><path d="M12 6.8v12.4M8.2 10.6h7.6"' + sk('mute', 2.2, ';stroke-linecap:round') + '/>',
    easter: egg('shell', 'violet'),
    eastermonday: egg('hill', 'sage'),
    eidfitr: '<path d="' + moon + '"' + fl(GOLD) + '/><path d="' + star5 + '"' + fl(GOLD) + '/>',
    eidadha: '<path d="' + moon + '"' + fl('teal') + '/><path d="' + star5 + '"' + fl(GOLD) + '/>',
    birthday: '<ellipse cx="12" cy="11.4" rx="6.4" ry="7.4"' + fl('red') + '/><ellipse cx="9.6" cy="8.6" rx="1.6" ry="2.4"' + fl(WHITE, ';opacity:.35') + '/><path d="M12 18.8l-1.4 1.8h2.8z"' + fl('red') + '/><path d="M12 20.6q-1.6 2 .4 3.6"' + sk('wire', 0.8) + '/>'
  };

  /* ── 3b: the seal’s one small accessory (40 × 40, seal fills it) ────── */
  var at = function (inner) { return '<g transform="translate(30 -6)">' + inner + '</g>'; };
  var sparkle = function (c) { return '<path d="M5 0l1 4 4 1-4 1-1 4-1-4-4-1 4-1z"' + fl(c) + '/><path d="M12 7l.6 2.2 2.2.6-2.2.6-.6 2.2-.6-2.2-2.2-.6 2.2-.6z"' + fl(c, ';opacity:.7') + '/>'; };
  var flagMark = '<path d="M1 0v18"' + sk('wire', 1.1) + '/><path d="M1 1h12v8H1z"' + fs(WHITE, 'ng', 0.7) + '/><path d="M1 1h4v8H1zM9 1h4v8H9z"' + fl('ng') + '/>';
  var leaf = '<path d="M2 12c0-6 4-10 10-10-1 6-5 10-10 10z"' + fl('sage') + '/>';
  var ACC = {
    newyear: at(sparkle('amber')),
    womensday: at('<g transform="translate(1 2)">' + petals(6, 6, 5, 3, 2.6, 'plum', 'yolk') + '</g>'),
    happiness: at('<circle cx="6" cy="6" r="4"' + fl('sun') + '/>' + ring(6, 6, 8, 5.4, 7.2, ['sun'], 1.1)),
    earthday: '<g transform="translate(29 28)"><path d="M2 12c0-6 4-10 10-10-1 6-5 10-10 10z"' + fl('leaf') + '/><path d="M2 12 9 5"' + sk('leaf-deep', 0.8) + '/></g>',
    workersday: at('<g transform="translate(1 1) scale(.42)">' + cog(WHITE) + '</g>'),
    africaday: '<g transform="translate(-4 -4)">' + ring(24, 24, 16, 23, 27, [GOLD], 1.4) + '</g>',
    childrensday: at('<path d="M6 0l5 6-5 7-5-7z"' + fl('orange') + '/><path d="M6 0v13M1 6h10"' + sk(WHITE, 0.6) + '/><path d="M6 13q-2 4 1 7"' + sk('wire', 0.7) + '/>'),
    democracyday: '<g transform="translate(33 -10)">' + flagMark + '</g>',
    independence: '<g transform="translate(33 -10)">' + flagMark + '</g>',
    founding: function (o) { return '<g transform="translate(4 36)"><path d="M0 0h32l-4 5 4 5H0l4-5z"' + fl(GOLDD) + '/><text x="16" y="7.6" text-anchor="middle" font-size="6" font-weight="700" letter-spacing="1" style="fill:' + V(WHITE) + ';font-family:var(--av-sans)">' + (o.years || 9) + ' YEARS</text></g>'; },
    youthday: at(sparkle('av-gold')),
    peaceday: '<g transform="translate(28 -4)"><path d="M1 12q6-2 11-10"' + sk('sage', 1) + '/><ellipse cx="5" cy="9" rx="2.4" ry="1.2" transform="rotate(-30 5 9)"' + fl('sage-2') + '/><ellipse cx="8.4" cy="5.6" rx="2.4" ry="1.2" transform="rotate(-50 8.4 5.6)"' + fl('sage-2') + '/><ellipse cx="10.6" cy="2.4" rx="2" ry="1" transform="rotate(-60 10.6 2.4)"' + fl('sage-2') + '/></g>',
    girlchild: at('<g transform="translate(1 2)">' + petals(6, 6, 5, 3, 2.5, 'pink', 'cream') + '</g>'),
    humanrights: at('<path d="M6 1v10M2 11h8M1 3.6h10"' + sk('steel', 1, ';stroke-linecap:round') + '/>'),
    christmas: at('<path d="M6 10c-3-1-5 0-6 2 3 0 4 1 6 0zM6 10c3-1 5 0 6 2-3 0-4 1-6 0z"' + fl('pine') + '/><circle cx="4.4" cy="9" r="1.7"' + fl('red') + '/><circle cx="7.4" cy="8.6" r="1.7"' + fl('red') + '/>'),
    goodfriday: at('<path d="M6 1v12M2.6 4.8h6.8"' + sk('mute', 1.6, ';stroke-linecap:round') + '/>'),
    easter: '<g transform="translate(29 28)">' + leaf + '<path d="M2 12c0-4-3-7-7-8 1 4 4 7 7 8z"' + fl('sage-2') + '/></g>',
    eastermonday: '<g transform="translate(29 28)">' + leaf + '</g>',
    eidfitr: '<g transform="translate(31 -5)"><path d="M7 1a6 6 0 1 0 4 10.4A5 5 0 0 1 7 1z"' + fl(GOLD) + '/></g>',
    eidadha: '<g transform="translate(33 -10)"><line x1="5" y1="0" x2="5" y2="4"' + sk('wire', 0.8) + '/><path d="M2 4h6l2 3H0z"' + fl('teal') + '/><path d="M0 7h10l-1.4 9H1.4z"' + fl(GOLD) + '/><path d="M2 16h6l-3 3z"' + fl('teal') + '/></g>',
    birthday: '<g transform="translate(28 -12)"><path d="M6 0l6 16H0z"' + fl('amber') + '/><path d="M2 10.6h8M3.6 6h4.8"' + sk('red', 1.4) + '/><circle cx="6" cy="0" r="1.8"' + fl('red') + '/></g>'
  };

  /* ── 3a: scenes (400 × 124; the seal sits at 8,36 · 56 px, the word at 74,78) ──
     W() tags an entrance (and an optional idle loop); the CSS plays them. */
  var DUR = { drop: 1.15, pop: 0.68, burst: 0.85, float: 1.5, fall: 1.15, fly: 1.3, spin: 1.1, grow: 0.7, unfurl: 0.85, flip: 0.44, fade: 0.8, draw: 0.9, trail: 0.56 };
  function mtag(type, d) { return 'class="avcel-m avcel-m--' + type + '" data-t="' + n1(d + DUR[type]) + '"'; }
  function W(type, d, inner, idle) {
    var body = idle ? '<g class="avcel-i avcel-i--' + idle + '" style="--di:' + n1(d + DUR[type]) + 's">' + inner + '</g>' : inner;
    return '<g ' + mtag(type, d) + ' style="--d:' + n1(d) + 's">' + body + '</g>';
  }
  /* A stroked line that draws itself. */
  function D(type, d, tag, attrs, stroke, w, extra) {
    return '<' + tag + ' ' + mtag(type, d) + ' pathLength="1" ' + attrs + ' style="--d:' + n1(d) + 's;fill:none;stroke:' + V(stroke) + ';stroke-width:' + w + (extra || '') + '"/>';
  }
  function bez(t) { var u = 1 - t; return [u * u * 70 + 2 * u * t * 200 + t * t * 334, u * u * 14 + 2 * u * t * 44 + t * t * 14]; }
  function line(n) { var p = []; for (var i = 0; i < n; i++) p.push(bez(i / (n - 1))); return p; }
  var STRING = D('draw', 0, 'path', 'd="M70 14 Q200 44 334 14"', 'wire', 1);

  var SC = {
    bunting: function (cols, edge) {
      var s = STRING;
      line(13).forEach(function (p, i) {
        var c = cols[i % cols.length];
        var style = c === WHITE ? fs(WHITE, edge || 'ng', 0.9) : fl(c);
        s += W('drop', 0.32 + i * 0.05, '<path d="M' + n1(p[0] - 7) + ' ' + n1(p[1]) + ' L' + n1(p[0] + 7) + ' ' + n1(p[1]) + ' L' + n1(p[0]) + ' ' + n1(p[1] + 15) + ' Z"' + style + '/>', 'sway');
      });
      return s;
    },
    lights: function () {
      var s = STRING;
      line(15).forEach(function (p, i) {
        var c = ['red', 'amber', 'pine'][i % 3];
        s += W('drop', 0.32 + i * 0.045, '<line x1="' + n1(p[0]) + '" y1="' + n1(p[1]) + '" x2="' + n1(p[0]) + '" y2="' + n1(p[1] + 4) + '"' + sk('wire', 1) + '/><ellipse cx="' + n1(p[0]) + '" cy="' + n1(p[1] + 8.5) + '" rx="3.4" ry="5"' + fl(c) + '/>', 'twinkle');
      });
      return s + W('pop', 1.15, '<path d="M88 2l2.4 5 5.5.6-4.1 3.7 1.2 5.4-5-2.8-5 2.8 1.2-5.4-4.1-3.7 5.5-.6z"' + fl('amber') + '/>', 'twinkle');
    },
    fireworks: function (cols) {
      var s = '';
      [[118, 22, 15], [214, 12, 11], [300, 24, 16], [356, 58, 9]].forEach(function (b, j) {
        var c = cols[j % cols.length], d = j * 0.26;
        s += D('trail', d, 'line', 'x1="' + b[0] + '" y1="70" x2="' + b[0] + '" y2="' + n1(b[1] + b[2] * 0.3) + '"', c, 1.4, ';stroke-linecap:round');
        s += W('burst', d + 0.36, ring(b[0], b[1], 12, b[2] * 0.35, b[2], [c], 1.6) + '<circle cx="' + b[0] + '" cy="' + b[1] + '" r="1.8"' + fl(c) + '/>', 'twinkle');
      });
      return s;
    },
    confetti: function (cols, d0) {
      var s = '';
      [[150, 36], [262, 40], [330, 6], [176, 6], [96, 44], [380, 30], [240, 26], [128, 8]].forEach(function (p, i) {
        s += W('fall', (d0 || 0) + i * 0.06, '<rect x="' + p[0] + '" y="' + p[1] + '" width="4" height="7" rx="1" transform="rotate(' + (i * 37) + ' ' + p[0] + ' ' + p[1] + ')"' + fl(cols[i % cols.length], ';opacity:.85') + '/>');
      });
      return s;
    },
    lanterns: function (body) {
      var s = W('pop', 0, '<path d="M262 8a14 14 0 1 0 9 24.5A11.5 11.5 0 0 1 262 8z"' + fl(GOLD) + '/>');
      [[318, 34, 0.2], [348, 22, 0.35], [374, 40, 0.5]].forEach(function (l) {
        var x = l[0], y = l[1];
        s += D('draw', l[2], 'line', 'x1="' + x + '" y1="0" x2="' + x + '" y2="' + y + '"', 'wire', 1);
        s += W('drop', l[2] + 0.3, '<path d="M' + (x - 4) + ' ' + y + 'h8l3 5h-14z"' + fl(body) + '/><path d="M' + (x - 7) + ' ' + (y + 5) + 'h14l-2 16h-10z"' + fl(GOLD) + '/><path d="M' + (x - 3) + ' ' + (y + 9) + 'h6v8h-6z"' + fl('wick') + '/><path d="M' + (x - 5) + ' ' + (y + 21) + 'h10l-5 5z"' + fl(body) + '/>', 'sway');
      });
      return s;
    },
    flowers: function (col) {
      var s = '';
      [[132, 22, 0], [214, 14, 0.15], [296, 26, 0.3], [356, 40, 0.45]].forEach(function (b) {
        s += D('draw', b[2], 'path', 'd="M' + b[0] + ' 80 C' + (b[0] - 4) + ' 60 ' + (b[0] + 4) + ' 44 ' + b[0] + ' ' + (b[1] + 8) + '"', 'sage', 1.4);
        s += W('pop', b[2] + 0.4, '<path d="M' + b[0] + ' 58c-6-1-9-5-9-10 6 1 9 5 9 10z"' + fl('sage-2') + '/>');
        s += W('pop', b[2] + 0.6, petals(b[0], b[1], 5, 5, 4.2, col, 'yolk'), 'bob');
      });
      return s;
    },
    balloons: function (cols, d0) {
      var s = '';
      [[300, 22, 0], [328, 12, 0.12], [356, 26, 0.24], [384, 16, 0.36]].forEach(function (b, i) {
        var c = cols[i % cols.length], x = b[0], y = b[1];
        s += W('float', (d0 || 0) + b[2], '<path d="M' + x + ' ' + (y + 14) + ' q-4 16 2 30 q4 12 -2 26"' + sk('wire', 0.9) + '/><ellipse cx="' + x + '" cy="' + y + '" rx="10" ry="12.5"' + fl(c) + '/><ellipse cx="' + n1(x - 3.4) + '" cy="' + (y - 4) + '" rx="2.4" ry="3.6"' + fl(WHITE, ';opacity:.35') + '/><path d="M' + x + ' ' + n1(y + 12.5) + 'l-2.2 3h4.4z"' + fl(c) + '/>', 'bob');
      });
      return s;
    },
    sunrise: function (sun, ray) {
      var r = '';
      for (var i = 0; i < 9; i++) {
        var a = Math.PI + i * Math.PI / 8;
        r += '<line x1="' + n1(318 + Math.cos(a) * 52) + '" y1="' + n1(70 + Math.sin(a) * 52) + '" x2="' + n1(318 + Math.cos(a) * 64) + '" y2="' + n1(70 + Math.sin(a) * 64) + '"' + sk(ray, 2, ';stroke-linecap:round') + '/>';
      }
      return W('float', 0, '<circle cx="318" cy="70" r="44"' + fl(sun) + '/>') + W('fade', 0.7, r) + '<rect class="avcel-ground" x="250" y="88" width="150" height="40"/>';
    },
    sprouts: function (d0) {
      var s = D('draw', d0 || 0, 'path', 'd="M70 96h290"', 'violet', 1.2, ';opacity:.4');
      [[110, 0], [190, 0.15], [270, 0.3], [350, 0.45]].forEach(function (b) {
        s += W('grow', (d0 || 0) + b[1] + 0.3, '<path d="M' + b[0] + ' 96c0-7 4-12 10-13-1 7-5 11-10 13z"' + fl('sage') + '/><path d="M' + b[0] + ' 96c0-6-3-10-8-11 1 6 4 9 8 11z"' + fl('sage-2') + '/>');
      });
      return s;
    },
    kente: function () {
      var cols = ['forest', GOLD, 'wine', 'soot'], s = '';
      for (var i = 0; i < 29; i++) s += W('flip', i * 0.028, '<rect x="' + n1(74 + i * 9.6) + '" y="92" width="9.6" height="7"' + fl(cols[i % 4]) + '/>');
      return s;
    },
    sun: function (x, y, c, d) { return W('spin', d, '<circle cx="' + x + '" cy="' + y + '" r="9"' + fl(c) + '/>' + ring(x, y, 12, 13, 18, [c], 1.6), 'rot'); },
    kites: function () {
      var s = '';
      [[300, 18, 'orange', 0], [340, 8, 'sky', 0.15], [376, 24, 'rose', 0.3]].forEach(function (k) {
        var x = k[0], y = k[1];
        s += W('fly', k[3], '<path d="M' + x + ' ' + y + 'l9 11-9 13-9-13z"' + fl(k[2]) + '/><path d="M' + x + ' ' + y + 'v24M' + (x - 9) + ' ' + (y + 11) + 'h18"' + sk(WHITE, 0.7) + '/><path d="M' + x + ' ' + (y + 24) + ' q-8 20 4 34 q8 10 -4 22"' + sk('wire', 0.8) + '/>', 'bob');
      });
      return s;
    },
    doves: function () {
      var s = D('draw', 0, 'path', 'd="M262 20q18 -4 30 -18"', 'sage', 1.2);
      [[296, 26, 1, 0.2], [336, 12, 0.8, 0.35], [370, 34, 0.65, 0.5]].forEach(function (d) {
        s += W('fly', d[3], '<g transform="translate(' + d[0] + ' ' + d[1] + ') scale(' + d[2] + ')"><path d="M-14 4c6-1 10-5 12-11 3 5 8 7 15 6-3 7-10 10-17 9l-5 5 .5-6c-3-1-4.6-2-5.5-3z"' + fs(WHITE, 'sky', 1.1, ';stroke-linejoin:round') + '/></g>', 'bob');
      });
      return s;
    },
    gears: function () {
      var s = '';
      [[300, 26, 14, 0], [334, 44, 10, 0.15], [366, 20, 12, 0.3]].forEach(function (g) { s += W('spin', g[3], gear(g[0], g[1], g[2], GOLD, PAPER), 'rot'); });
      return s;
    },
    hills: function () {
      return W('float', 0, '<path d="M70 100c40-18 70-20 110-6s80 10 120-8 70-10 100 2v20H70z"' + fl('hill') + '/>') +
        W('float', 0.15, '<path d="M70 104c50-10 90-6 130 4s90 6 130-6 60-6 70 0v14H70z"' + fl('hill-2') + '/>');
    },
    rings: function () {
      var s = '';
      for (var i = 0; i < 5; i++) s += D('draw', i * 0.12, 'circle', 'cx="' + (286 + i * 22) + '" cy="30" r="13"', i % 2 ? GOLD : 'steel', 2.4);
      return s;
    },
    cross: function () {
      return D('draw', 0, 'path', 'd="M330 30V104"', 'mute', 5, ';stroke-linecap:round') +
        D('draw', 0.45, 'path', 'd="M312 50h36"', 'mute', 5, ';stroke-linecap:round') +
        W('float', 0.1, '<path d="M250 104c30-10 60-14 80-14s50 4 70 14z"' + fl('mist') + '/>') +
        W('fade', 0.9, ring(330, 50, 10, 30, 40, ['lilac'], 1.2));
    },
    blossoms: function () {
      var s = '';
      [[150, 18], [214, 30], [270, 10], [320, 24], [372, 14], [356, 52], [240, 52]].forEach(function (p, i) { s += W('pop', i * 0.08, petals(p[0], p[1], 5, 3, 2.6, 'pink', 'cream'), 'bob'); });
      return s;
    },
    stars: function () {
      var s = '';
      [[290, 40, 1, 0], [322, 20, 0.7, 0.12], [356, 34, 0.9, 0.24], [384, 10, 0.6, 0.36]].forEach(function (p) {
        s += W('pop', p[3], '<path transform="translate(' + p[0] + ' ' + p[1] + ') scale(' + p[2] + ')" d="M0-12l3.5 7.8 8.5.9-6.4 5.7 1.8 8.4L0 6.5-7.4 10.8l1.8-8.4L-12-3.3l8.5-.9z"' + fl('av-gold') + '/>', 'twinkle');
      });
      return s + W('fade', 0.5, '<path d="M262 60q30-10 40-46"' + sk('av-gold', 1, ';stroke-dasharray:2 3') + '/>');
    },
    bigYears: function (o) { return W('float', 0, '<text x="300" y="112" font-size="128" font-weight="500" style="fill:' + V('nine') + ';font-family:var(--av-serif)">' + (o.years || 9) + '</text>'); },
    ribbon: function (o) { return W('unfurl', 0.25, '<path d="M74 92h196l-8 7 8 7H74l8-7z"' + fl(GOLDD) + '/><text x="172" y="103" font-size="8.5" font-weight="700" letter-spacing="2" text-anchor="middle" style="fill:' + V(WHITE) + ';font-family:var(--av-sans)">' + String(o.yearsWord || 'Nine').toUpperCase() + ' YEARS · ' + (o.since || 2017) + ' – ' + (o.year || 2026) + '</text>'); },
    cake: function () {
      return W('pop', 0.7, '<g transform="translate(250 66)"><rect x="0" y="14" width="34" height="18" rx="3"' + fl('av-gold-light') + '/><path d="M0 20q4 4 8.5 0t8.5 0 8.5 0 8.5 0"' + sk(WHITE, 2) + '/><rect x="15.5" y="4" width="3" height="10" rx="1"' + fl('red') + '/>' + W('pop', 0.95, '<ellipse cx="17" cy="2" rx="2" ry="3"' + fl('amber') + '/>', 'flicker') + '</g>');
    }
  };
  var bigFlag = W('drop', 0.95, '<g transform="translate(351 42)">' + flagMark + '</g>', 'sway');
  var SCENE = {
    newyear: function () { return { f: SC.fireworks(['amber', GOLD, GOLDD]) + SC.confetti(['red', 'amber', 'navy'], 1.1) }; },
    womensday: function () { return { f: SC.flowers('plum') }; },
    girlchild: function () { return { f: SC.blossoms() }; },
    happiness: function () { return { f: SC.balloons(['sun', 'orange', 'sun', 'rose']) + SC.confetti(['sun', 'orange', 'rose'], 0.6) }; },
    earthday: function () { return { b: SC.hills(), f: SC.sun(370, 26, 'amber', 0.5) + SC.sprouts(0.3) }; },
    workersday: function () { return { f: SC.gears() }; },
    africaday: function () { return { f: SC.kente() + SC.sun(370, 26, 'amber', 0.8) }; },
    childrensday: function () { return { f: SC.kites() + SC.confetti(['orange', 'sky', 'av-gold', 'rose'], 0.7) }; },
    democracyday: function () { return { f: SC.bunting(['ng', WHITE]) }; },
    independence: function () { return { f: SC.bunting(['ng', WHITE]) + bigFlag }; },
    founding: function (o) { return { b: SC.bigYears(o), f: SC.ribbon(o) + SC.balloons(['amber', 'red'], 0.5) }; },
    youthday: function () { return { f: SC.stars() }; },
    peaceday: function () { return { f: SC.doves() }; },
    humanrights: function () { return { f: SC.rings() }; },
    christmas: function () { return { f: SC.lights() }; },
    goodfriday: function () { return { b: SC.cross() }; },
    easter: function () { return { b: SC.sunrise('dawn', 'dawn-ray'), f: SC.sprouts(0.6) }; },
    eastermonday: function () { return { b: SC.sunrise('dawn-2', 'dawn-ray-2'), f: SC.sprouts(0.6) }; },
    eidfitr: function () { return { f: SC.lanterns('teal') }; },
    eidadha: function () { return { f: SC.lanterns('clay') }; },
    birthday: function () { return { f: SC.bunting(['red', 'amber', 'sky', 'rose']) + SC.balloons(['red', 'amber', 'sky', 'plum'], 0.5) + SC.cake() }; }
  };

  /* 2b banner icons (24 × 24 line drawings). Only the occasions the design draws. */
  var ICON = {
    christmas: 'M12 2.5l1 2 2.2.3-1.6 1.5.4 2.2-2-1-2 1 .4-2.2-1.6-1.5 2.2-.3zM12 9l4.5 5.5h-2.5l3.5 4.5H6.5l3.5-4.5H7.5zM12 19v2.5',
    eidfitr: 'M14.5 3.2a8.8 8.8 0 1 0 6.3 14.6A7.2 7.2 0 0 1 14.5 3.2zM19 4.5l.7 1.5 1.6.2-1.2 1.1.3 1.6-1.4-.8-1.4.8.3-1.6-1.2-1.1 1.6-.2z',
    independence: 'M5 21.5V3M5 4h14.5v9.5H5M9.8 4v9.5M14.7 4v9.5',
    newyear: 'M12 2v4.5M12 17.5V22M2 12h4.5M17.5 12H22M4.9 4.9l3.2 3.2M15.9 15.9l3.2 3.2M4.9 19.1l3.2-3.2M15.9 8.1l3.2-3.2M12 10.5v3M10.5 12h3',
    africaday: 'M12 7.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zM12 1.5v3M12 19.5v3M1.5 12h3M19.5 12h3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1',
    easter: 'M2.5 18.5h19M6 18.5a6 6 0 0 1 12 0M12 6.5v3M5.2 9.8l2 2M18.8 9.8l-2 2M2.8 14.5h2.4M18.8 14.5h2.4',
    womensday: 'M12 22v-8M12 14c-3.2 0-5.5-2.3-5.5-5.5 3.2 0 5.5 2.3 5.5 5.5zM12 14c3.2 0 5.5-2.3 5.5-5.5-3.2 0-5.5 2.3-5.5 5.5zM12 8.5a2.6 2.6 0 1 0 0-5.2 2.6 2.6 0 0 0 0 5.2z',
    founding: 'M8 2l4 6.5L16 2M12 22a6.2 6.2 0 1 0 0-12.4 6.2 6.2 0 0 0 0 12.4zM12.6 13.3a1.6 1.6 0 1 0 0 3.2c.9 0 1.4-.7 1.4-1.6 0 2.2-1 3.7-2.6 3.7'
  };
  ICON.eidadha = ICON.eidfitr; ICON.democracyday = ICON.independence; ICON.eastermonday = ICON.easter;

  var val = function (x, o) { return typeof x === 'function' ? x(o || {}) : (x || ''); };

  window.AvCelArt = {
    META: META,
    has: function (k) { return !!SCENE[k]; },
    /* The day’s symbol: an <svg> sized in em so it sits in the wordmark like a letter. */
    glyph: function (k, o) { return '<svg class="avcel-glyph" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + val(GLYPH[k] || GLYPH.newyear, o) + '</svg>'; },
    glyphInner: function (k, o) { return val(GLYPH[k] || GLYPH.newyear, o); },
    /* The seal’s accessory, drawn over a 40 × 40 seal box. */
    acc: function (k, o) { return ACC[k] ? '<svg class="avcel-acc" viewBox="0 0 40 40" aria-hidden="true" focusable="false">' + val(ACC[k], o) + '</svg>' : ''; },
    accInner: function (k, o) { return val(ACC[k], o); },
    /* The scene: {b: behind the word, f: in front}. */
    scene: function (k, o) { var s = SCENE[k] ? SCENE[k](o || {}) : SCENE.newyear(o || {}); return { b: s.b || '', f: s.f || '' }; },
    icon: function (k) { return ICON[k] ? '<svg class="avcel-ban-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + ICON[k] + '"/></svg>' : ''; }
  };
})();
