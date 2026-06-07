import React from 'react';
import { FileX, SearchX, Upload } from 'lucide-react';

export default function EmptyState({ type = 'default', title, subtitle, action }) {
  const icons = {
    noPdfs:    Upload,
    noSearch:  SearchX,
    noFailed:  FileX,
    default:   FileX,
  };
  const Icon = icons[type] || icons.default;

  return (
    <div className="empty-state">
      <div className="empty-state-icon">
        <Icon size={28} />
      </div>
      {title && <div className="empty-state-title">{title}</div>}
      {subtitle && <div className="empty-state-sub">{subtitle}</div>}
      {action}
    </div>
  );
}
