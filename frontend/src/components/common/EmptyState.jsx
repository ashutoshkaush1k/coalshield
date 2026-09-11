// Zero-data state.
export function EmptyState({ children = "Nothing to show yet." }) {
  return <div className="empty">{children}</div>;
}
