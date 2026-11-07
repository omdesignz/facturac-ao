/**
 * Up to two letters for a name's avatar, from its capitalised words, so
 * "Mavinga & Filhos, Lda." is MF and "Hotel Baía Azul" is HB.
 */
export function initials(name: string): string {
    const letters = name
        .split(/\s+/)
        .filter((word) => /^\p{Lu}/u.test(word))
        .slice(0, 2)
        .map((word) => word[0])
        .join('');

    return letters || name.trim().charAt(0).toUpperCase();
}
