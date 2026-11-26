/* AllStore :: reusable Bangladesh division / district / upazila-thana cascade.
   Used by checkout and the customer address book. Other pages can listen for
   the `bd-location-change` event to react (checkout refreshes shipping rates).

   With data-simple-bd="1" the division selector is hidden and the district
   selector lists every district; the division is derived from the chosen
   district on the client and revalidated on the server. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const country = $('country');
  const division = $('division_id');
  const district = $('district_id');
  const area = $('area_id');
  if (!country || !division || !district || !area) return;

  const block = $('bdLocationFields');
  const city = $('city');
  const region = $('region');
  const simple = !!(block && block.dataset.simpleBd === '1');
  const needsShipping = () => !block || block.dataset.needsShipping !== '0';
  let hierarchy = null;
  let hierarchyPromise = null;
  // Keep any value already chosen in server-rendered HTML, or selected by the
  // customer before /api/locations finishes loading. A cold production start
  // can otherwise repopulate the selectors and silently drop that selection.
  const selected = {
    division: division.dataset.selected || division.value || '',
    district: district.dataset.selected || district.value || '',
    area: area.dataset.selected || area.value || '',
  };
  const captureSelection = () => {
    selected.division = division.value || '';
    selected.district = district.value || '';
    selected.area = area.value || '';
  };

  const option = (value, label) => {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = label;
    return o;
  };
  const label = (row) => row.name + (row.en && row.en !== row.name ? ' · ' + row.en : '');
  const divisions = () => (hierarchy && Array.isArray(hierarchy.divisions) ? hierarchy.divisions : []);
  const findDivision = (id) => divisions().find((d) => d.id === id) || null;
  const findDistrict = (divisionId, id) => {
    const d = findDivision(divisionId);
    return d ? (d.districts || []).find((x) => x.id === id) || null : null;
  };
  const findArea = (divisionId, districtId, id) => {
    const x = findDistrict(divisionId, districtId);
    return x ? (x.areas || []).find((a) => a.id === id) || null : null;
  };
  const findDistrictAny = (id) => {
    for (const d of divisions()) {
      const x = (d.districts || []).find((row) => row.id === id);
      if (x) { return [d, x]; }
    }
    return [null, null];
  };
  const fill = (select, rows, placeholder, selectedId) => {
    select.textContent = '';
    select.appendChild(option('', placeholder));
    rows.forEach((row) => select.appendChild(option(row.id, label(row))));
    select.value = selectedId || '';
  };
  const load = () => {
    if (hierarchy) return Promise.resolve(hierarchy);
    if (hierarchyPromise) return hierarchyPromise;
    hierarchyPromise = fetch('/api/locations', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((j) => {
        hierarchy = j.success && j.data && Array.isArray(j.data.divisions) ? j.data : { divisions: [] };
        return hierarchy;
      })
      .catch(() => {
        hierarchy = { divisions: [] };
        return hierarchy;
      });
    return hierarchyPromise;
  };
  const changed = () => document.dispatchEvent(new CustomEvent('bd-location-change'));
  const autoFill = (force) => {
    if (country.value !== 'BD') return;
    const d = findDivision(division.value);
    const x = findDistrict(division.value, district.value);
    const a = findArea(division.value, district.value, area.value);
    if (city && a && (!city.value || city.dataset.bdAuto === '1' || force)) {
      city.value = a.name || a.en || '';
      city.dataset.bdAuto = '1';
    }
    if (region && d && x && (!region.value || region.dataset.bdAuto === '1' || force)) {
      region.value = (d.name || d.en || '') + ' / ' + (x.name || x.en || '');
      region.dataset.bdAuto = '1';
    }
  };
  const updateAreas = (preferred) => {
    const x = findDistrict(division.value, district.value);
    fill(area, x ? x.areas || [] : [], 'Select upazila / থানা', preferred || selected.area || '');
    selected.area = '';
    autoFill(true);
  };
  const updateDistricts = (preferred) => {
    const d = findDivision(division.value);
    fill(district, d ? d.districts || [] : [], 'Select district / জেলা', preferred || selected.district || '');
    selected.district = '';
    updateAreas(preferred ? '' : selected.area);
  };
  const populate = () => {
    const currentDivision = division.value || selected.division || '';
    const currentDistrict = selected.district || district.value || '';
    const currentArea = selected.area || area.value || '';
    fill(division, divisions(), 'Select division / বিভাগ', currentDivision);
    selected.division = '';
    selected.district = currentDistrict;
    selected.area = currentArea;
    updateDistricts();
    division.required = district.required = area.required = country.value === 'BD';
  };

  // Simplified BD checkout: all districts in one selector, no visible division.
  const fillDistrictsSimple = (preferred) => {
    district.textContent = '';
    district.appendChild(option('', 'Select district / জেলা'));
    for (const d of divisions()) {
      for (const x of (d.districts || [])) {
        const o = document.createElement('option');
        o.value = x.id;
        o.textContent = (x.name || x.en || '') + (x.en && x.en !== x.name ? ' · ' + x.en : '') + ' — ' + (d.name || d.en || '');
        district.appendChild(o);
      }
    }
    district.value = preferred || selected.district || '';
  };
  const updateAreasSimple = (preferred) => {
    const [d, x] = findDistrictAny(district.value);
    division.value = d ? d.id : '';
    fill(area, x ? x.areas || [] : [], 'Select thana / থানা', preferred || selected.area || '');
    selected.area = '';
    autoFill(true);
  };
  const populateSimple = () => {
    const currentDivision = selected.division || division.value || '';
    const currentDistrict = selected.district || district.value || '';
    const currentArea = selected.area || area.value || '';
    fill(division, divisions(), 'Select division', currentDivision);
    selected.division = '';
    fillDistrictsSimple(currentDistrict);
    selected.district = '';
    updateAreasSimple(currentArea);
    division.required = false;
    district.required = area.required = country.value === 'BD' && needsShipping();
  };

  const applyCountry = (initial) => {
    const isBd = country.value === 'BD';
    if (block) block.hidden = !isBd || !needsShipping();
    division.disabled = !isBd;
    district.disabled = area.disabled = !isBd || !needsShipping();
    division.required = district.required = area.required = false;
    if (!isBd) {
      division.value = district.value = area.value = '';
      if (simple) { fillDistrictsSimple(''); } else { fill(district, [], 'Select district / জেলা', ''); }
      fill(area, [], 'Select upazila / থানা', '');
      changed();
      return;
    }
    load().then(() => {
      if (!initial) { selected.division = selected.district = selected.area = ''; }
      if (simple) { populateSimple(); } else { populate(); }
      changed();
    });
  };

  if (simple) {
    country.addEventListener('change', () => applyCountry(false));
    district.addEventListener('change', () => { captureSelection(); updateAreasSimple(); changed(); });
    area.addEventListener('change', () => { captureSelection(); autoFill(true); changed(); });
  } else {
    country.addEventListener('change', () => applyCountry(false));
    division.addEventListener('change', () => { captureSelection(); updateDistricts(); changed(); });
    district.addEventListener('change', () => { captureSelection(); updateAreas(); changed(); });
    area.addEventListener('change', () => { captureSelection(); autoFill(true); changed(); });
  }
  if (city) city.addEventListener('input', () => { delete city.dataset.bdAuto; });
  if (region) region.addEventListener('input', () => { delete region.dataset.bdAuto; });
  captureSelection();
  applyCountry(true);
})();
