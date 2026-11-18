export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'rejected';

/** Money fields are exact decimal strings in USD. */
export type Generation = {
    id: string;
    status: GenerationStatus;
    is_final: boolean;
    status_note: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost: string;
    max_cost: string;
    actual_cost: string | null;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

/** From the backend's Arrayable::toSelectable(). */
export type SelectOption = {
    id: string;
    name: string;
};

export type GenerationOptions = {
    sizes: SelectOption[];
    qualities: SelectOption[];
    maxPromptCharacters: number;
    maxReferenceImages: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        per_page: number;
        links: PaginationLink[];
    };
};

export type CostRange = {
    expected: string;
    min: string;
    max: string;
};

export type EstimateLine = {
    type: string;
    label: string;
    tokens: { min: number; expected: string; max: number };
    rate_per_million: string;
    expected_cost: string;
};

export type CostEstimate = {
    customer: CostRange;
    provider: CostRange;
    markup: string;
    lines: EstimateLine[];
};

export type GenerationInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};

export type Credits = {
    balance: string;
    reserved: string;
    packages: string[];
};
