/* 欠場者一覧を「どの範囲で出すか」の選択肢を作る。
   受付システムの欠場報告タブ（uketsuke.html）と欠場者一覧ページ（uketsuke-absent.html）の
   両方が使う。同じ並べ方を2か所に書くと、片方だけ直したときに
   ボタンから開いた範囲と一覧ページの選び直しが食い違うため、ここ1本にまとめる。

   値の書き方は t:<受付締切の時刻> と e:<区分コード>。
   文字はそのまま（エスケープしていない）返すので、画面に入れる側でエスケープすること。 */
var UkScope = (function () {
  'use strict';

  /* 受付締切の時刻を「その日の何分」になおす。
     文字の並びで比べると「9:30」が「10:30」「12:00」より後になってしまうため。 */
  function timeKey(t) {
    var s = String(t || '').replace(/[０-９]/g, function (ch) {
      return String.fromCharCode(ch.charCodeAt(0) - 0xFEE0);
    });
    var m = /(\d{1,2})\s*[:：時]\s*(\d{1,2})?/.exec(s) || /^\s*(\d{1,2})\s*$/.exec(s);
    if (!m) return Infinity;
    return Number(m[1]) * 60 + Number(m[2] || 0);
  }

  /* 区分名が未設定（コードのまま）のとき「JAS JAS」と二重に出さない */
  function label(e) {
    var n = (e && e.name) ? String(e.name).trim() : '';
    return (!n || n === e.code) ? String(e.code) : e.code + ' ' + n;
  }

  /* 受付締切が同じ区分をまとめる。締切の早い順。締切が未設定の区分は入れない。 */
  function deadlines(events) {
    var seen = {}, out = [];
    (events || []).forEach(function (e) {
      var t = (e.start_time || '').trim();
      if (!t) return;
      if (!seen[t]) { seen[t] = []; out.push(t); }
      seen[t].push(e);
    });
    out.sort(function (a, b) { var x = timeKey(a), y = timeKey(b); return x === y ? 0 : (x < y ? -1 : 1); });
    return out.map(function (t) { return { time: t, events: seen[t] }; });
  }

  /* 画面に出す選択肢。[{group:見出し, items:[{value, label}]}] */
  function options(events) {
    var out = [];
    var dl = deadlines(events);
    if (dl.length) {
      out.push({
        group: '受付締切ごと（その時刻の区分をそれぞれ表示）',
        items: dl.map(function (g) {
          return {
            value: 't:' + g.time,
            label: g.time + '　' + g.events.map(function (e) { return e.code; }).join(', ')
          };
        })
      });
    }
    out.push({
      group: '区分ごと',
      items: (events || []).map(function (e) {
        return {
          value: 'e:' + e.code,
          label: label(e) + (e.start_time ? '（' + e.start_time + '）' : '')
        };
      })
    });
    return out;
  }

  return { timeKey: timeKey, deadlines: deadlines, label: label, options: options };
})();
