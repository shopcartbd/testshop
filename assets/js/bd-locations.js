/* AllStore :: reusable Bangladesh division / district / upazila-thana cascade.
   Used by checkout and the customer address book. Other pages can listen for
   the `bd-location-change` event to react (checkout refreshes shipping rates). */
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
  let hierarchy = null;
  let hierarchyPromise = null;
  const selected = {
    division: division.dataset.selected || '',
    district: district.dataset.selected || '',
    area: area.dataset.selected || '',
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
    fill(division, divisions(), 'Select division / বিভাগ', selected.division || '');
    selected.division = '';
    updateDistricts();
    division.required = district.required = area.required = country.value === 'BD';
  };
  const applyCountry = (initial) => {
    const isBd = country.value === 'BD';
    if (block) block.hidden = !isBd;
    division.disabled = district.disabled = area.disabled = !isBd;
    division.required = district.required = area.required = false;
    if (!isBd) {
      division.value = district.value = area.value = '';
      fill(district, [], 'Select district / জেলা', '');
      fill(area, [], 'Select upazila / থানা', '');
      changed();
      return;
    }
    load().then(() => {
      if (!initial) {
        selected.division = selected.district = selected.area = '';
      }
      populate();
      changed();
    });
  };

  country.addEventListener('change', () => applyCountry(false));
  division.addEventListener('change', () => { updateDistricts(); changed(); });
  district.addEventListener('change', () => { updateAreas(); changed(); });
  area.addEventListener('change', () => { autoFill(true); changed(); });
  if (city) city.addEventListener('input', () => { delete city.dataset.bdAuto; });
  if (region) region.addEventListener('input', () => { delete region.dataset.bdAuto; });
  applyCountry(true);
})();
