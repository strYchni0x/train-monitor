/* German Regional Train Monitor - admin selection (station -> line -> directions). */
(function () {
    'use strict';
    if (typeof TrainMonAdmin === 'undefined') { return; }

    var i18n = TrainMonAdmin.i18n || {};
    var $ = function (id) { return document.getElementById(id); };
    var q = $('trainmon-q'),
        searchBtn = $('trainmon-search'),
        stationsDiv = $('trainmon-stations'),
        lineWrap = $('trainmon-line-wrap'),
        lineSel = $('trainmon-line'),
        saveWrap = $('trainmon-save-wrap'),
        msg = $('trainmon-msg');

    if (!searchBtn) { return; }

    function post(action, params) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', TrainMonAdmin.nonce);
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(TrainMonAdmin.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function setMsg(text) { msg.textContent = text || ''; }

    function reset(fromStep) {
        if (fromStep <= 2) { lineWrap.style.display = 'none'; lineSel.innerHTML = ''; }
        saveWrap.style.display = 'none';
        ['trainmon-line-value', 'trainmon-dir0-key', 'trainmon-dir0-terminus', 'trainmon-dir1-key', 'trainmon-dir1-terminus'].forEach(function (id) { $(id).value = ''; });
    }

    // Step 1: search station
    function doSearch() {
        var term = (q.value || '').trim();
        if (!term) { setMsg(i18n.enterTerm); return; }
        setMsg(i18n.searching);
        stationsDiv.innerHTML = '';
        reset(2);
        $('trainmon-eva').value = '';
        $('trainmon-name').value = '';
        post('trainmon_search_stations', { q: term }).then(function (res) {
            if (!res.success) { setMsg(res.data && res.data.message ? res.data.message : i18n.searchFail); return; }
            setMsg('');
            if (!res.data.length) { stationsDiv.textContent = i18n.noStations; return; }
            res.data.forEach(function (st, i) {
                var id = 'trainmon-st-' + i;
                var label = document.createElement('label');
                label.style.display = 'block';
                label.style.margin = '.2em 0';
                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'trainmon-station-pick';
                radio.value = st.eva;
                radio.id = id;
                radio.setAttribute('data-name', st.name);
                radio.addEventListener('change', function () { onStationChosen(st.eva, st.name); });
                label.appendChild(radio);
                label.appendChild(document.createTextNode(' ' + st.name + ' (' + st.eva + ')'));
                stationsDiv.appendChild(label);
            });
        }).catch(function () { setMsg(i18n.netSearch); });
    }

    // Step 2: scan lines at the station
    function onStationChosen(eva, name) {
        $('trainmon-eva').value = eva;
        $('trainmon-name').value = name;
        reset(2);
        setMsg(i18n.scanning);
        post('trainmon_scan_lines', { eva: eva }).then(function (res) {
            if (!res.success) { setMsg(res.data && res.data.message ? res.data.message : i18n.scanFail); return; }
            setMsg('');
            lineSel.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = i18n.chooseLine;
            lineSel.appendChild(ph);
            res.data.forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = item.line;
                opt.textContent = item.line;
                opt.setAttribute('data-directions', JSON.stringify(item.directions || []));
                lineSel.appendChild(opt);
            });
            lineWrap.style.display = '';
        }).catch(function () { setMsg(i18n.netScan); });
    }

    // Step 3: line chosen -> adopt directions
    function onLineChosen() {
        var opt = lineSel.options[lineSel.selectedIndex];
        if (!opt || !opt.value) { saveWrap.style.display = 'none'; return; }
        var dirs = [];
        try { dirs = JSON.parse(opt.getAttribute('data-directions') || '[]'); } catch (e) { dirs = []; }
        $('trainmon-line-value').value = opt.value;
        var d0 = dirs[0] || { key: '', terminus: '', label: '' };
        var d1 = dirs[1] || { key: '', terminus: '', label: '' };
        $('trainmon-dir0-key').value = d0.key || '';
        $('trainmon-dir0-terminus').value = d0.terminus || '';
        $('trainmon-dir0-label').value = d0.label || '';
        $('trainmon-dir1-key').value = d1.key || '';
        $('trainmon-dir1-terminus').value = d1.terminus || '';
        $('trainmon-dir1-label').value = d1.label || '';
        // Only show the second direction field if there is a second direction.
        $('trainmon-dir1-label').parentNode.parentNode.style.display = d1.key ? '' : 'none';
        saveWrap.style.display = '';
    }

    searchBtn.addEventListener('click', doSearch);
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
    lineSel.addEventListener('change', onLineChosen);
})();
