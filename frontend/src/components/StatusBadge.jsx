import React from 'react';

export default function StatusBadge({ status }) {
  const map = {
    indexed:    { cls: 'badge-indexed',    label: 'Indexed' },
    queued:     { cls: 'badge-queued',     label: 'Queued' },
    processing: { cls: 'badge-processing', label: 'Processing' },
    failed:     { cls: 'badge-failed',     label: 'Failed' },
    uploaded:   { cls: 'badge-uploaded',   label: 'Uploaded' },
  };
  const { cls, label } = map[status] || { cls: 'badge-uploaded', label: status };
  return <span className={`badge ${cls}`}>{label}</span>;
}
