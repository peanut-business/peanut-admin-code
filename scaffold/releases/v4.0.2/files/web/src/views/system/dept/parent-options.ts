export const ROOT_DEPARTMENT_ID = 0;

export interface ParentDepartmentRecord {
  id: number;
  name: string;
  children?: ParentDepartmentRecord[];
}

export interface ParentDepartmentOption {
  id: number;
  name: string;
  children: ParentDepartmentOption[];
}

export function initialParentDepartmentId(parentId?: number): number {
  return parentId ?? ROOT_DEPARTMENT_ID;
}

export function buildParentDepartmentOptions(
  departments: ParentDepartmentRecord[],
  rootLabel: string
): ParentDepartmentOption[] {
  const mapDepartments = (
    nodes: ParentDepartmentRecord[]
  ): ParentDepartmentOption[] =>
    nodes.map((node) => ({
      id: node.id,
      name: node.name,
      children: mapDepartments(node.children || []),
    }));

  return [
    {
      id: ROOT_DEPARTMENT_ID,
      name: rootLabel,
      children: [],
    },
    ...mapDepartments(departments),
  ];
}
