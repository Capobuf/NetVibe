export type Company = {
    id: number;
    name: string;
};

export type CreatedCompany = Company & { site_id: number };

export type Site = {
    id: number;
    name: string | null;
};
