export type ItemMenu = { href: string; texto: string; icone: string };
export type GrupoMenu = { id: string; texto: string; icone: string; itens: ItemMenu[] };

// Caminhos das rotas retaguarda.painel, retaguarda.editais.index, retaguarda.eventos.index, retaguarda.vlibras.edit,
// retaguarda.homologacao.index, retaguarda.proponentes.index, retaguarda.avaliadores.index, retaguarda.prefeituras-bairro.index, retaguarda.areas-atuacao.index,
// retaguarda.integracao-ia.edit, retaguarda.email.edit e retaguarda.usuarios.index (o front não tem helper de rotas nomeadas).
// O menu lateral (MenuRetaguarda) e a trilha do topo de cada página (PaginaRetaguarda) saem daqui.

/** Tela inicial da retaguarda: fica no topo do menu, fora dos grupos. */
export const itemPainel: ItemMenu = {
    href: '/retaguarda/painel',
    texto: 'Painel',
    icone: 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
};

export const gruposMenu: GrupoMenu[] = [
    // Cadastros principais: os editais, os proponentes do portal (só consulta) e os avaliadores.
    {
        id: 'menu-grupo-cadastro',
        texto: 'Cadastro',
        icone: 'M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 12h6M9 16h4',
        itens: [
            {
                href: '/retaguarda/editais',
                texto: 'Editais',
                icone: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
            },
            {
                href: '/retaguarda/proponentes',
                texto: 'Proponentes',
                icone: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
            },
            {
                href: '/retaguarda/avaliadores',
                texto: 'Avaliadores',
                icone: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 11-5.7M16 18l2 2 4-4',
            },
        ],
    },
    // Inscrições finalizadas no portal, para o gestor conferir e homologar.
    {
        id: 'menu-grupo-inscricoes',
        texto: 'Inscrições',
        icone: 'M9 3h6v4H9zM9 5H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-3M9 14l2 2 4-4',
        itens: [
            {
                href: '/retaguarda/homologacao',
                texto: 'Homologação',
                icone: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6zM9 12l2 2 4-4',
            },
        ],
    },
    {
        id: 'menu-grupo-portal',
        texto: 'Portal',
        icone: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
        itens: [
            {
                href: '/retaguarda/eventos',
                texto: 'Eventos',
                icone: 'M4 6h16v14H4zM4 10h16M9 3v5M15 3v5',
            },
        ],
    },
    // Tabelas de apoio e ajustes, usados pela retaguarda e pelo portal.
    {
        id: 'menu-grupo-parametrizacao',
        texto: 'Parametrização',
        icone: 'M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4',
        itens: [
            {
                href: '/retaguarda/prefeituras-bairro',
                texto: 'Prefeituras-Bairro',
                icone: 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
            },
            {
                href: '/retaguarda/areas-atuacao',
                texto: 'Áreas de atuação',
                icone: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
            },
            {
                href: '/retaguarda/integracao-ia',
                texto: 'Integração com IA',
                icone: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 15v4M17 17h4M5 3v3M3.5 4.5h3',
            },
            {
                href: '/retaguarda/email',
                texto: 'E-mail',
                icone: 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 7l9 6 9-6',
            },
            {
                href: '/retaguarda/vlibras',
                texto: 'VLibras',
                icone: 'M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15',
            },
        ],
    },
    {
        id: 'menu-grupo-administracao',
        texto: 'Administração',
        icone: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z',
        itens: [
            {
                href: '/retaguarda/usuarios',
                texto: 'Usuários',
                icone: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.6',
            },
        ],
    },
];

/** A página atual é o item ou uma tela dele (/novo, /12/edit, ?busca=...). */
export const ehItemAtual = (url: string, href: string): boolean => url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`);

/** Grupo e item do menu da página atual, para a trilha; o Painel não tem grupo, e telas fora do menu (perfil, senha) não têm nada. */
export const localNoMenu = (url: string): { grupo: GrupoMenu | null; item: ItemMenu } | null => {
    if (ehItemAtual(url, itemPainel.href)) return { grupo: null, item: itemPainel };
    for (const grupo of gruposMenu) {
        const item = grupo.itens.find((candidato) => ehItemAtual(url, candidato.href));
        if (item) return { grupo, item };
    }
    return null;
};
