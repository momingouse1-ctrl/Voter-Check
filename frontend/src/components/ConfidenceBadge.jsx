import React from 'react';

export default function ConfidenceBadge({ confidence }) {
  const map = {
    exact:  { cls: 'badge-exact',  label: 'Exact Match' },
    high:   { cls: 'badge-high',   label: 'High Match' },
    medium: { cls: 'badge-medium', label: 'Partial Match' },
    low:    { cls: 'badge-low',    label: 'Fuzzy Match' },
  };
  const { cls, label } = map[confidence] || { cls: 'badge-low', label: confidence };
  return <span className={`badge ${cls}`}>{label}</span>;
}
