/* AllStore :: reusable Bangladesh division / district / upazila-thana cascade.
   Used by checkout and the customer address book. Division is a visible first
   step, followed by district and thana/upazila. Other pages can listen for the
   `bd-location-change` event to react (checkout refreshes shipping rates).

   A cold production start can repopulate selectors after an early choice; the
   selected values are captured and restored. A district/area pair submitted
   without a division (older saved addresses) is resolved to its division. */
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
  const needsShipping = () => !block || block.dataset.needsShipping !== '0';
  let hierarchy = null;
  let hierarchyPromise = null;
  // Keep any value already chosen in server-rendered HTML, or selected by the
  // customer before /api/locations finishes loading.
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

  const option = (value, text) => {
    const o = document.createElement('option');
    o.value = value;
    o.textContent = text;
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
  const findAreaAny = (id) => {
    for (const d of divisions()) {
      for (const x of (d.districts || [])) {
        const a = (x.areas || []).find((row) => row.id === id);
        if (a) { return [d, x, a]; }
      }
    }
    return [null, null, null];
  };
  const districtBelongsToDivision = (divisionId, districtId) => {
    const d = findDivision(divisionId);
    return Boolean(d && (d.districts || []).some((x) => x.id === districtId));
  };
  /* The page can be server-rendered with a district (or thana) that is not part
     of the chosen division — a saved address, or a fast tap before the hierarchy
     finished loading. Resolving the pair keeps the area select usable instead of
     leaving the customer with an empty thana list. */
  const healSelection = () => {
    if (!hierarchy) return false;
    if (area.value) {
      const [ad, ax] = findAreaAny(area.value);
      if (ad && ax) {
        const areaId = area.value;
        const divisionChanged = division.value !== ad.id;
        const districtChanged = district.value !== ax.id;
        if (divisionChanged) { division.value = ad.id; }
        if (districtChanged) {
          selected.district = ax.id;
          updateDistricts();
          district.value = ax.id;
        }
        if (divisionChanged || districtChanged) {
          updateAreas(areaId);
          area.value = areaId;
          return true;
        }
      }
    }
    if (district.value && !districtBelongsToDivision(division.value, district.value)) {
      const [d, x] = findDistrictAny(district.value);
      if (d && x) {
        division.value = d.id;
        selected.district = x.id;
        selected.area = area.value || '';
        updateDistricts();
        return true;
      }
    }
    return false;
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
    const currentDistrict = selected.district || district.value || '';
    const currentArea = selected.area || area.value || '';
    let currentDivision = division.value || selected.division || '';
    if (!currentDivision && currentDistrict) {
      const [d] = findDistrictAny(currentDistrict);
      if (d) currentDivision = d.id;
    }
    // The server renders every district in the select, so a fast tap can pick a
    // district that belongs to another division. Follow that district's real
    // division instead of dropping it and leaving the thana list empty.
    if (currentDistrict && currentDivision && hierarchy
        && !districtBelongsToDivision(currentDivision, currentDistrict)) {
      const [d] = findDistrictAny(currentDistrict);
      if (d) { currentDivision = d.id; }
    }
    fill(division, divisions(), 'Select division / বিভাগ', currentDivision);
    selected.division = '';
    selected.district = currentDistrict;
    selected.area = currentArea;
    updateDistricts();
    healSelection();
    const required = country.value === 'BD' && needsShipping();
    division.required = district.required = area.required = required;
  };

  const applyCountry = (initial) => {
    const isBd = country.value === 'BD';
    const active = isBd && needsShipping();
    if (block) block.hidden = !active;
    division.disabled = district.disabled = area.disabled = !active;
    division.required = district.required = area.required = false;
    if (!isBd) {
      division.value = district.value = area.value = '';
      fill(district, [], 'Select district / জেলা', '');
      fill(area, [], 'Select upazila / থানা', '');
      changed();
      return;
    }
    load().then(() => {
      if (!initial) { selected.division = selected.district = selected.area = ''; }
      populate();
      changed();
    });
  };

  // Before /api/locations resolves, keep the early selection and wait for the
  // hierarchy instead of clearing the dependent selects with an empty lookup.
  const earlyChange = () => {
    captureSelection();
    if (hierarchy) return false;
    load().then(() => { populate(); changed(); });
    return true;
  };
  country.addEventListener('change', () => applyCountry(false));
  division.addEventListener('change', () => { if (!earlyChange()) { updateDistricts(); changed(); } });
  district.addEventListener('change', () => { if (!earlyChange()) { healSelection(); updateAreas(); changed(); } });
  area.addEventListener('change', () => { if (!earlyChange()) { if (healSelection()) { changed(); return; } autoFill(true); changed(); } });
  if (city) city.addEventListener('input', () => { delete city.dataset.bdAuto; });
  if (region) region.addEventListener('input', () => { delete region.dataset.bdAuto; });
  captureSelection();
  applyCountry(true);
})();
