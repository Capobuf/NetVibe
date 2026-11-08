export type Company = {
    id: number;
    name: string;
    observation_retention_days?: number;
};

export type CreatedCompany = Company & { site_id: number };

export type Site = {
    id: number;
    name: string | null;
};
