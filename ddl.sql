CREATE TABLE if not exists receive_table
(
    uid          bigserial,
    id           text NOT NULL,                                     -- 'Идентификатор ответа'
    node_id      text,                                              -- 'Идентификатор запроса'
    content      text NOT NULL,                                     -- 'Содержимое ответа' -- XML
    ref_id       text,                                              -- 'Ссылка'
    ref_group_id text,                                              -- 'Ссылка на группу'
    created_at   timestamp(0) with time zone DEFAULT now(),
    updated_at   timestamp(0) with time zone,
    deleted_at   timestamp(0) with time zone,
    request_id   text,                                              -- Переменные необходимые для заполнения шаблона
    message_type text,                                              -- 'Тип сообщения'
    error_code   smallint                    DEFAULT '0'::smallint, -- 'Код ошибки'
    error_text   text,                                              -- 'Текст ошибки'
    CONSTRAINT receive_table_pkey PRIMARY KEY (uid)
);
COMMENT ON TABLE receive_table
    IS 'Ответы из СМЭВ';
COMMENT ON COLUMN receive_table.id
    IS 'Идентификатор ответа';
COMMENT ON COLUMN receive_table.node_id
    IS 'Идентификатор запроса';
COMMENT ON COLUMN receive_table.content
    IS 'Содержимое ответа';
COMMENT ON COLUMN receive_table.ref_id
    IS 'Ссылка';
COMMENT ON COLUMN receive_table.ref_group_id
    IS 'Ссылка на группу';
COMMENT ON COLUMN receive_table.request_id
    IS 'Переменные необходимые для заполнения шаблона';
COMMENT ON COLUMN receive_table.message_type
    IS 'Тип сообщения';
COMMENT ON COLUMN receive_table.error_code
    IS 'Код ошибки';
COMMENT ON COLUMN receive_table.error_text
    IS 'Текст ошибки';

CREATE TABLE if not exists send_table
(
    uid         bigserial,
    id          text    NOT NULL,                                  -- 'Идентификатор запроса'
    content     text    NOT NULL,                                  -- 'Содержимое запроса'
    status      text    NOT NULL,                                  -- 'Статус'
    created_at  timestamp(0) with time zone DEFAULT now(),
    updated_at  timestamp(0) with time zone,
    deleted_at  timestamp(0) with time zone,
    foiv_id     bigint,
    user_id     bigint,
    "user"      text,
    template_id bigint,
    is_complete boolean NOT NULL            DEFAULT false,         -- 'Выполнено?'
    error_code  smallint                    DEFAULT '0'::smallint, -- 'Код ошибки'
    error_text  text,                                              -- 'Текст ошибки'
    bindings    json,                                              -- 'Переменные необходимые для заполнения шаблона'
    CONSTRAINT send_table_pkey PRIMARY KEY (uid),
    CONSTRAINT send_table_id_unique UNIQUE (id)
);
COMMENT ON TABLE send_table
    IS 'Запросы в СМЭВ';
COMMENT ON COLUMN send_table.id
    IS 'Идентификатор запроса';
COMMENT ON COLUMN send_table.content
    IS 'Содержимое запроса';
COMMENT ON COLUMN send_table.status
    IS 'Статус';
COMMENT ON COLUMN send_table.is_complete
    IS 'Выполнено?';
COMMENT ON COLUMN send_table.error_code
    IS 'Код ошибки';
COMMENT ON COLUMN send_table.error_text
    IS 'Текст ошибки';
COMMENT ON COLUMN send_table.bindings
    IS 'Переменные необходимые для заполнения шаблона';

alter table receive_table
    add is_processed boolean default false;

comment on column receive_table.is_processed
    is 'Флаг сообщение обработано';
