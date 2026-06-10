import React, { useState, useEffect } from 'react';
import { ChevronDown, MapPin } from 'lucide-react';
import api from '../lib/api';

/**
 * GeographyFilters — cascading district/city/assembly/polling-station dropdowns.
 *
 * Props:
 *   value    — { district_id, city_id, assembly_id, polling_station_id }
 *   onChange — callback({ district_id, city_id, assembly_id, polling_station_id })
 *   scope    — 'all' | 'district' | 'city' | 'assembly' | 'polling_station'
 */
export default function GeographyFilters({ value = {}, onChange, scope = 'all' }) {
  const [districts,       setDistricts]       = useState([]);
  const [cities,          setCities]          = useState([]);
  const [assemblies,      setAssemblies]      = useState([]);
  const [pollingStations, setPollingStations] = useState([]);
  const [loadingCities,   setLoadingCities]   = useState(false);
  const [loadingAssem,    setLoadingAssem]    = useState(false);
  const [loadingPS,       setLoadingPS]       = useState(false);

  const districtId       = value.district_id       || '';
  const cityId           = value.city_id           || '';
  const assemblyId       = value.assembly_id       || '';
  const pollingStationId = value.polling_station_id || '';

  // Load districts once
  useEffect(() => {
    api.get('/filters/districts').then(r => setDistricts(r.data)).catch(() => {});
  }, []);

  // Load cities when district changes
  useEffect(() => {
    if (!districtId) { setCities([]); return; }
    setLoadingCities(true);
    api.get('/filters/cities', { params: { district_id: districtId } })
      .then(r => setCities(r.data))
      .catch(() => setCities([]))
      .finally(() => setLoadingCities(false));
  }, [districtId]);

  // Load assemblies when district changes
  useEffect(() => {
    if (!districtId) { setAssemblies([]); return; }
    setLoadingAssem(true);
    api.get('/filters/assemblies', { params: { district_id: districtId } })
      .then(r => setAssemblies(r.data))
      .catch(() => setAssemblies([]))
      .finally(() => setLoadingAssem(false));
  }, [districtId]);

  // Load polling stations when district/city/assembly change
  useEffect(() => {
    if (!districtId) { setPollingStations([]); return; }
    setLoadingPS(true);
    api.get('/filters/polling-stations', { params: { district_id: districtId, city_id: cityId, assembly_id: assemblyId } })
      .then(r => setPollingStations(r.data))
      .catch(() => setPollingStations([]))
      .finally(() => setLoadingPS(false));
  }, [districtId, cityId, assemblyId]);

  const handleChange = (field, val) => {
    const next = { ...value, [field]: val || undefined };
    // Reset downstream when parent changes
    if (field === 'district_id') {
      next.city_id = undefined;
      next.assembly_id = undefined;
      next.polling_station_id = undefined;
    } else if (field === 'city_id' || field === 'assembly_id') {
      next.polling_station_id = undefined;
    }
    onChange?.(next);
  };

  if (scope === 'all') return null;

  return (
    <div className="geo-filters">
      {/* District */}
      <div className="geo-filter-item">
        <label className="geo-filter-label"><MapPin size={12} /> District</label>
        <div className="geo-select-wrap">
          <select
            className="geo-select"
            value={districtId}
            onChange={e => handleChange('district_id', e.target.value)}
          >
            <option value="">All Districts</option>
            {districts.map(d => (
              <option key={d.id} value={d.id}>
                {d.name_en}{d.name_te ? ` — ${d.name_te}` : ''}
              </option>
            ))}
          </select>
          <ChevronDown size={14} className="geo-select-icon" />
        </div>
      </div>

      {/* City — only show if district selected */}
      {districtId && (
        <div className="geo-filter-item">
          <label className="geo-filter-label">City / Town</label>
          <div className="geo-select-wrap">
            <select
              className="geo-select"
              value={cityId}
              onChange={e => handleChange('city_id', e.target.value)}
              disabled={loadingCities}
            >
              <option value="">All {districts.find(d => d.id == districtId)?.name_en || ''}</option>
              {cities.map(c => (
                <option key={c.id} value={c.id}>
                  {c.name_en}{c.name_te ? ` — ${c.name_te}` : ''}
                </option>
              ))}
            </select>
            <ChevronDown size={14} className="geo-select-icon" />
          </div>
        </div>
      )}

      {/* Assembly — only show if district selected */}
      {districtId && assemblies.length > 0 && (
        <div className="geo-filter-item">
          <label className="geo-filter-label">Assembly</label>
          <div className="geo-select-wrap">
            <select
              className="geo-select"
              value={assemblyId}
              onChange={e => handleChange('assembly_id', e.target.value)}
              disabled={loadingAssem}
            >
              <option value="">All Assemblies</option>
              {assemblies.map(a => (
                <option key={a.id} value={a.id}>
                  {a.name_en}{a.name_te ? ` — ${a.name_te}` : ''}
                </option>
              ))}
            </select>
            <ChevronDown size={14} className="geo-select-icon" />
          </div>
        </div>
      )}

      {/* Polling Station — only show if district selected and stations exist */}
      {districtId && pollingStations.length > 0 && (
        <div className="geo-filter-item">
          <label className="geo-filter-label">Polling Station</label>
          <div className="geo-select-wrap">
            <select
              className="geo-select"
              value={pollingStationId}
              onChange={e => handleChange('polling_station_id', e.target.value)}
              disabled={loadingPS}
            >
              <option value="">All Stations</option>
              {pollingStations.map(ps => (
                <option key={ps.id} value={ps.id}>
                  {ps.station_number ? `#${ps.station_number} — ` : ''}{ps.station_name_en || ps.station_name_te || 'Station'}
                </option>
              ))}
            </select>
            <ChevronDown size={14} className="geo-select-icon" />
          </div>
        </div>
      )}
    </div>
  );
}
